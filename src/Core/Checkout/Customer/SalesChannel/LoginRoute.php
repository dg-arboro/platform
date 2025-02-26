<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Customer\SalesChannel;

use OpenApi\Annotations as OA;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\Event\CustomerBeforeLoginEvent;
use Shopware\Core\Checkout\Customer\Event\CustomerLoginEvent;
use Shopware\Core\Checkout\Customer\Exception\BadCredentialsException;
use Shopware\Core\Checkout\Customer\Exception\CustomerAuthThrottledException;
use Shopware\Core\Checkout\Customer\Exception\CustomerNotFoundException;
use Shopware\Core\Checkout\Customer\Exception\InactiveCustomerException;
use Shopware\Core\Checkout\Customer\Password\LegacyPasswordVerifier;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\RateLimiter\Exception\RateLimitExceededException;
use Shopware\Core\Framework\RateLimiter\RateLimiter;
use Shopware\Core\Framework\Routing\Annotation\ContextTokenRequired;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Routing\Annotation\Since;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\CartRestorer;
use Shopware\Core\System\SalesChannel\ContextTokenResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @RouteScope(scopes={"store-api"})
 * @ContextTokenRequired()
 */
class LoginRoute extends AbstractLoginRoute
{
    private EventDispatcherInterface $eventDispatcher;

    private EntityRepositoryInterface $customerRepository;

    private LegacyPasswordVerifier $legacyPasswordVerifier;

    private CartRestorer $restorer;

    private RequestStack $requestStack;

    private RateLimiter $rateLimiter;

    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        EntityRepositoryInterface $customerRepository,
        LegacyPasswordVerifier $legacyPasswordVerifier,
        CartRestorer $restorer,
        RequestStack $requestStack,
        RateLimiter $rateLimiter
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->customerRepository = $customerRepository;
        $this->legacyPasswordVerifier = $legacyPasswordVerifier;
        $this->restorer = $restorer;
        $this->requestStack = $requestStack;
        $this->rateLimiter = $rateLimiter;
    }

    public function getDecorated(): AbstractLoginRoute
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * @Since("6.2.0.0")
     * @OA\Post(
     *      path="/account/login",
     *      summary="Log in a customer",
     *      description="Logs in customers given their credentials.",
     *      operationId="loginCustomer",
     *      tags={"Store API", "Login & Registration"},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={
     *                  "username",
     *                  "password"
     *              },
     *              @OA\Property(property="username", description="Email", type="string"),
     *              @OA\Property(property="password", description="Password", type="string")
     *          )
     *      ),
     *      @OA\Response(
     *          response="200",
     *          description="A successful login returns a context token which is associated with the logged in user. Use that as your `sw-context-token` header for subsequent requests.",
     *          @OA\JsonContent(ref="#/components/schemas/ContextTokenResponse")
     *     ),
     *      @OA\Response(
     *          response="401",
     *          description="If credentials are incorrect an error is returned",
     *          @OA\JsonContent(ref="#/components/schemas/failure")
     *     )
     * )
     * @Route(path="/store-api/account/login", name="store-api.account.login", methods={"POST"})
     */
    public function login(RequestDataBag $data, SalesChannelContext $context): ContextTokenResponse
    {
        $email = $data->get('email', $data->get('username'));

        if (empty($email) || empty($data->get('password'))) {
            throw new BadCredentialsException();
        }

        $event = new CustomerBeforeLoginEvent($context, $email);
        $this->eventDispatcher->dispatch($event);

        if ($this->requestStack->getMainRequest() !== null) {
            $cacheKey = strtolower($email) . '-' . $this->requestStack->getMainRequest()->getClientIp();

            try {
                $this->rateLimiter->ensureAccepted(RateLimiter::LOGIN_ROUTE, $cacheKey);
            } catch (RateLimitExceededException $exception) {
                throw new CustomerAuthThrottledException($exception->getWaitTime(), $exception);
            }
        }

        try {
            $customer = $this->getCustomerByLogin(
                $email,
                $data->get('password'),
                $context
            );
        } catch (CustomerNotFoundException | BadCredentialsException $exception) {
            throw new UnauthorizedHttpException('json', $exception->getMessage());
        }

        if (isset($cacheKey)) {
            $this->rateLimiter->reset(RateLimiter::LOGIN_ROUTE, $cacheKey);
        }

        if (!$customer->getActive()) {
            throw new InactiveCustomerException($customer->getId());
        }

        $context = $this->restorer->restore($customer->getId(), $context);
        $newToken = $context->getToken();

        $this->customerRepository->update([
            [
                'id' => $customer->getId(),
                'lastLogin' => new \DateTimeImmutable(),
            ],
        ], $context->getContext());

        $event = new CustomerLoginEvent($context, $customer, $newToken);
        $this->eventDispatcher->dispatch($event);

        return new ContextTokenResponse($newToken);
    }

    private function getCustomerByLogin(string $email, string $password, SalesChannelContext $context): CustomerEntity
    {
        $customer = $this->getCustomerByEmail($email, $context);

        if ($customer->hasLegacyPassword()) {
            if (!$this->legacyPasswordVerifier->verify($password, $customer)) {
                throw new BadCredentialsException();
            }

            $this->updatePasswordHash($password, $customer, $context->getContext());

            return $customer;
        }

        if (!password_verify($password, $customer->getPassword() ?? '')) {
            throw new BadCredentialsException();
        }

        return $customer;
    }

    private function getCustomerByEmail(string $email, SalesChannelContext $context): CustomerEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customer.email', $email));

        $result = $this->customerRepository->search($criteria, $context->getContext());

        $result = $result->filter(static function (CustomerEntity $customer) use ($context) {
            // Skip guest users
            if ($customer->getGuest()) {
                return null;
            }

            // If not bound, we still need to consider it
            if ($customer->getBoundSalesChannelId() === null) {
                return true;
            }

            // It is bound, but not to the current one. Skip it
            if ($customer->getBoundSalesChannelId() !== $context->getSalesChannel()->getId()) {
                return null;
            }

            return true;
        });

        if ($result->count() !== 1) {
            throw new BadCredentialsException();
        }

        return $result->first();
    }

    private function updatePasswordHash(string $password, CustomerEntity $customer, Context $context): void
    {
        $this->customerRepository->update([
            [
                'id' => $customer->getId(),
                'password' => $password,
                'legacyPassword' => null,
                'legacyEncoder' => null,
            ],
        ], $context);
    }
}
