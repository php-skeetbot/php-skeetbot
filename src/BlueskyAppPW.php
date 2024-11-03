<?php
/**
 * Class BlueskyAppPW
 *
 * @created      22.10.2024
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2024 smiley
 * @license      MIT
 */
declare(strict_types=1);

namespace PHPSkeetBot\PHPSkeetBot;

use chillerlan\HTTP\Utils\MessageUtil;
use chillerlan\HTTP\Utils\QueryUtil;
use chillerlan\OAuth\Core\AccessToken;
use chillerlan\OAuth\Core\OAuth2Provider;
use chillerlan\OAuth\Core\TokenRefresh;
use chillerlan\OAuth\Providers\ProviderException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use function date;
use function property_exists;
use function sprintf;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * Bluesky (with app password)
 *
 * Note: this Bluesky provider only supports the native app password authentication. OAuth2 is still in the works.
 *
 * @link https://docs.bsky.app/docs/advanced-guides/api-directory
 * @link https://docs.bsky.app/docs/category/http-reference
 * @link https://bsky.app/settings/app-passwords
 * @link https://github.com/chillerlan/php-oauth/issues/3
 */
class BlueskyAppPW extends OAuth2Provider implements TokenRefresh{

	public const IDENTIFIER = 'BLUESKYAPPPW';

	protected string      $authorizationURL = 'https://bsky.social/xrpc';
	protected string      $apiURL           = 'https://bsky.social/xrpc';
	protected string|null $apiDocs          = 'https://docs.bsky.app/docs/category/http-reference';
	protected string|null $userRevokeURL    = 'https://bsky.app/settings/app-passwords';

	/**
	 * We'll override this method here in order to keep empty fields because the bsky API is picky
	 *
	 * @inheritDoc
	 *
	 * @link https://github.com/bluesky-social/atproto/issues/2938
	 */
	protected function cleanBodyParams(iterable $params):array{
		return QueryUtil::cleanParams($params, QueryUtil::BOOLEANS_AS_BOOL, false);
	}

	/**
	 * @throws \chillerlan\OAuth\Providers\ProviderException
	 */
	public function getAuthorizationURL(array|null $params = null, array|null $scopes = null):UriInterface{
		throw new ProviderException('Bluesky does not support authentication (yet).');
	}

	/**
	 * @throws \chillerlan\OAuth\Providers\ProviderException
	 */
	public function getAccessToken(string $code, string|null $state = null):AccessToken{
		throw new ProviderException('Bluesky does not support authentication (yet).');
	}

	public function resolveHandle(string $handle):string{
		$handle = strtolower(trim($handle, "\ \n\r\t\v\0@"));

		if($handle === ''){
			throw new ProviderException('invalid handle');
		}

		$request = $this->requestFactory
			->createRequest('GET', QueryUtil::merge($this->apiURL.'/com.atproto.identity.resolveHandle', ['handle' => $handle]))
		;

		$response = $this->http->sendRequest($request);

		if($response->getStatusCode() === 200){
			$json = MessageUtil::decodeJSON($response);

			if(property_exists($json, 'did')){
				return (string)$json->did;
			}
		}

		throw new ProviderException('could not resolve the given handle');
	}

	public function getAccountDID():string{
		$extraParams = $this->storage->getAccessToken($this->name)->extraParams;

		if(isset($extraParams['did'])){
			return (string)$extraParams['did'];
		}

		throw new ProviderException('invalid session');
	}

	public function getHandle():string{
		$extraParams = $this->storage->getAccessToken($this->name)->extraParams;

		if(isset($extraParams['handle'])){
			return (string)$extraParams['handle'];
		}

		throw new ProviderException('invalid session');
	}

	public function createSession(string $identifier, string $appPassword):static{

		if(!str_starts_with($identifier, 'did:')){
			$identifier = $this->resolveHandle($identifier);
		}

		$request = $this->requestFactory
			->createRequest('POST', $this->authorizationURL.'/com.atproto.server.createSession')
			->withHeader('Content-Type', 'application/json')
		;

		$request  = $this->setRequestBody(['identifier' => $identifier, 'password' => $appPassword], $request);
		$response = $this->http->sendRequest($request);

		$this->parseAuthResponse($response);

		return $this;
	}

	public function refreshAccessToken(AccessToken|null $token = null):AccessToken{
		$token        ??= $this->storage->getAccessToken($this->name);
		$refreshToken   = $token->refreshToken;

		if(empty($refreshToken)){
			$msg = 'no refresh token available, token expired [%s]';

			throw new ProviderException(sprintf($msg, date('Y-m-d h:i:s A', $token->expires)));
		}

		$request = $this->requestFactory
			->createRequest('POST', $this->authorizationURL.'/com.atproto.server.refreshSession')
			->withHeader('Authorization', 'Bearer '.$refreshToken)
		;

		$response = $this->http->sendRequest($request);

		return $this->parseAuthResponse($response);
	}

	protected function parseAuthResponse(ResponseInterface $response):AccessToken{

		if($response->getStatusCode() !== 200){
			// @todo: detailed error message
			throw new ProviderException('could not create session');
		}

		$json = MessageUtil::decodeJSON($response, true);

		if(!isset($json['accessJwt'], $json['refreshJwt'])){
			throw new ProviderException('invalid session response');
		}

		$token = $this->createAccessToken();

		$token->accessToken  = $json['accessJwt'];
		$token->refreshToken = $json['refreshJwt'];
		// expiry is 2 hours according to https://bsky.app/profile/jaz.bsky.social/post/3k7ij2dxwmj2s
		$token->expires      = 7200;

		unset($json['accessJwt'], $json['refreshJwt']);

		$token->extraParams = $json;

		$this->storeAccessToken($token);

		return $token;
	}

}
