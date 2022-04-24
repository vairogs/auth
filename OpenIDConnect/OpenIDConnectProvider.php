<?php declare(strict_types = 1);

namespace Vairogs\Auth\OpenIDConnect;

use DateTime;
use Exception;
use JetBrains\PhpStorm\ArrayShape;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Token\RegisteredClaims;
use League\OAuth2\Client\Grant\AbstractGrant;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken as BaseAccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use UnexpectedValueException;
use Vairogs\Auth\OpenIDConnect\Configuration\AbstractProvider;
use Vairogs\Auth\OpenIDConnect\Configuration\Constraint\Equal;
use Vairogs\Auth\OpenIDConnect\Configuration\Constraint\Exists;
use Vairogs\Auth\OpenIDConnect\Configuration\Constraint\GreaterOrEqual;
use Vairogs\Auth\OpenIDConnect\Configuration\Constraint\Hashed;
use Vairogs\Auth\OpenIDConnect\Configuration\Constraint\IssuedBy;
use Vairogs\Auth\OpenIDConnect\Configuration\Constraint\LesserOrEqual;
use Vairogs\Auth\OpenIDConnect\Configuration\Constraint\SignedWith;
use Vairogs\Auth\OpenIDConnect\Configuration\ParsedToken;
use Vairogs\Auth\OpenIDConnect\Configuration\Uri;
use Vairogs\Auth\OpenIDConnect\Exception\OpenIDConnectException;
use Vairogs\Auth\OpenIDConnect\Utils\Traits\OpenIDConnectProviderVariables;
use Vairogs\Core\Registry\HasRegistry;
use Vairogs\Extra\Constants\ContentType;
use Vairogs\Utils\Helper\Char;
use Vairogs\Utils\Helper\Identification;
use Vairogs\Utils\Helper\Json;
use Vairogs\Utils\Helper\Util;
use function array_merge;
use function base64_encode;
use function property_exists;
use function sprintf;

abstract class OpenIDConnectProvider extends AbstractProvider implements HasRegistry
{
    use OpenIDConnectProviderVariables;

    public function __construct(protected string $name, protected readonly RouterInterface $router, RequestStack $requestStack, array $options = [], array $collaborators = [])
    {
        try {
            $this->session = $requestStack->getCurrentRequest()?->getSession();
        } catch (Exception) {
            // exception === use already set session (default: null)
        }

        parent::__construct(options: $options, collaborators: $collaborators);

        if ([] !== $options) {
            $this->state = Identification::getUniqueId();
            $this->configure(options: $options);
        }
    }

    /**
     * @throws IdentityProviderException
     */
    public function getAccessToken($grant, array $options = []): AccessTokenInterface|BaseAccessToken
    {
        $accessToken = $this->getAccessTokenFunction(grant: $grant, options: $options);

        if (null === $accessToken || null === $token = $accessToken->getIdToken()) {
            throw new OpenIDConnectException(message: 'Expected an id_token but did not receive one from the authorization server');
        }

        $this->setValidators();
        $this->validatorChain->assert(token: $token);

        $this->saveSession(accessToken: $accessToken);

        return $accessToken;
    }

    /**
     * @throws IdentityProviderException
     */
    public function getRefreshToken($token, array $options = []): array|string|ResponseInterface
    {
        return $this->getTokenResponse(request: $this->getRefreshTokenRequest(params: array_merge(['token' => $token, 'grant_type' => 'refresh_token'], $options)));
    }

    /**
     * @throws IdentityProviderException
     */
    public function getAccessTokenFunction($grant, array $options = []): ?ParsedToken
    {
        $grant = $this->verifyGrant(grant: $grant);

        $params = $grant->prepareRequestParameters(defaults: ['redirect_uri' => $this->redirectUri], options: $options);
        $request = $this->getAccessTokenRequest(params: $params);
        $response = $this->getTokenResponse(request: $request);
        $prepared = $this->prepareAccessTokenResponse(result: $response);

        return $this->createAccessToken(response: $prepared, grant: $grant);
    }

    public function getPublicKey(): Key
    {
        return Key\InMemory::plainText(contents: $this->publicKey);
    }

    /**
     * @throws IdentityProviderException
     */
    public function getValidateToken($token, array $options = []): array|string|ResponseInterface
    {
        return $this->getTokenResponse(request: $this->getValidateTokenRequest(params: array_merge(['token' => $token], $options)));
    }

    /**
     * @throws IdentityProviderException
     */
    public function getTokenResponse(RequestInterface $request): array
    {
        $response = $this->getResponse(request: $request);
        $this->statusCode = $response->getStatusCode();
        $parsed = $this->parseResponse(response: $response);
        /* @var array $parsed */
        $this->checkResponse(response: $response, data: $parsed);

        return $parsed;
    }

    /**
     * @throws IdentityProviderException
     */
    public function getRevokeToken($token, array $options = []): array|string|ResponseInterface
    {
        return $this->getTokenResponse(request: $this->getRevokeTokenRequest(params: array_merge(['token' => $token], $options)));
    }

    protected function configure(array $options = []): void
    {
        $this->redirectUri = match ($options['redirect']['type']) {
            'uri' => $options['redirect']['uri'],
            'route' => $this->router->generate(name: $options['redirect']['route'], parameters: $options['redirect']['params'] ?? [], referenceType: UrlGeneratorInterface::ABSOLUTE_URL),
            default => null,
        };

        $uris = $options['uris'] ?? [];
        unset($options['redirect'], $options['uris']);

        foreach (Util::makeOneDimension(array: $options, maxDepth: 0) as $key => $value) {
            if (property_exists(object_or_class: $this, property: $var = Char::toCamelCase(string: $key))) {
                $this->{$var} = $value;
            }
        }

        $this->setPublicKey(publicKey: 'file://' . $this->publicKey);
        $this->buildUris(uris: $uris);
    }

    protected function buildUris(array $uris): void
    {
        foreach ($uris as $name => $uri) {
            $params = [
                'client_id' => $this->clientId,
                'redirect_uri' => $this->redirectUri,
                'state' => $this->state,
                'base_uri' => $this->baseUri,
                'base_uri_post' => $this->baseUriPost ?? $this->baseUri,
            ];
            $this->uris[$name] = (new Uri(options: $uri, extra: $params, method: $uri['method'] ?? Request::METHOD_POST))
                ->setUseSession(useSession: $this->useSession)
                ->setSession(session: $this->session);
        }
    }

    protected function getRefreshTokenRequest(array $params): RequestInterface
    {
        return $this->getRequest(method: Request::METHOD_POST, url: $this->getRefreshTokenUrl(), options: $this->getAccessTokenOptions(params: $params));
    }

    protected function getValidateTokenRequest(array $params): RequestInterface
    {
        return $this->getRequest(method: Request::METHOD_POST, url: $this->getValidateTokenUrl(), options: $this->getBaseTokenOptions(params: $params));
    }

    protected function getRevokeTokenRequest(array $params): RequestInterface
    {
        return $this->getRequest(method: Request::METHOD_POST, url: $this->getRevokeTokenUrl(), options: $this->getAccessTokenOptions(params: $params));
    }

    protected function setValidators(): void
    {
        $this->validatorChain
            ->setAssertions(assertions: [
                (new SignedWith(signer: $this->signer, key: $this->getPublicKey()))->setRequired(required: true),
                (new IssuedBy(issuers: $this->getIdTokenIssuer()))->setRequired(required: true),
                (new Equal(expected: $this->clientId))->setClaim(claim: 'azp'),
                (new Equal(expected: [$this->clientId]))->setClaim(claim: RegisteredClaims::AUDIENCE),
                (new Hashed())->setClaim(claim: 'at_hash')->setRequired(required: true),
                (new Exists())->setClaim(claim: RegisteredClaims::SUBJECT)->setRequired(required: true),
                (new Exists())->setClaim(claim: RegisteredClaims::ISSUED_AT)->setRequired(required: true),
                (new GreaterOrEqual(expected: (new DateTime())->getTimestamp()))->setClaim(claim: RegisteredClaims::EXPIRATION_TIME)->setRequired(required: true),
                (new LesserOrEqual(expected: (new DateTime())->getTimestamp()))->setClaim(claim: RegisteredClaims::NOT_BEFORE),
            ]);
    }

    /**
     * @throws UnexpectedValueException
     */
    protected function parseJson($content): array
    {
        if (empty($content)) {
            return [];
        }

        try {
            return Json::decode(json: $content, flags: Json::ASSOCIATIVE);
        } catch (Exception $exception) {
            throw new UnexpectedValueException(message: sprintf('Failed to parse JSON response: %s', $exception->getMessage()), previous: $exception);
        }
    }

    #[ArrayShape([
        'headers' => 'string[]',
        'body' => 'mixed',
    ])]
    protected function getAccessTokenOptions(array $params): array
    {
        $options = $this->getBaseTokenOptions(params: $params);
        $options['headers']['authorization'] = 'Basic ' . base64_encode(string: $this->clientId . ':' . $this->clientSecret);

        return $options;
    }

    #[ArrayShape([
        'headers' => 'string[]',
        'body' => 'mixed',
    ])]
    protected function getBaseTokenOptions(array $params): array
    {
        return [
            'headers' => [
                'content-type' => ContentType::X_WWW_FORM_URLENCODED,
            ],
            'body' => $this->getAccessTokenBody(params: $params),
        ];
    }

    protected function getAccessTokenBody(array $params): string
    {
        return $this->buildQueryString(params: $params);
    }

    protected function saveSession(ParsedToken $accessToken): void
    {
        if ($this->useSession && null !== $this->session) {
            $this->session->set(name: 'access_token', value: $accessToken->getToken());
            $this->session->set(name: 'refresh_token', value: $accessToken->getRefreshToken());
            $this->session->set(name: 'id_token', value: $accessToken->getIdTokenHint());
        }
    }

    protected function getAccessTokenRequest(array $params): RequestInterface
    {
        return $this->getRequest(method: Request::METHOD_POST, url: $this->getAccessTokenUrl(params: $params), options: $this->getAccessTokenOptions(params: $params));
    }

    protected function createAccessToken(array $response, ?AbstractGrant $grant = null): ?ParsedToken
    {
        if ($this->check(response: $response)) {
            return new ParsedToken(options: $response);
        }

        return null;
    }
}
