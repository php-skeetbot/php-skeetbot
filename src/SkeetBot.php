<?php
/**
 * Class SkeetBot
 *
 * @created      26.10.2024
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2024 smiley
 * @license      MIT
 */
declare(strict_types=1);

namespace PHPSkeetBot\PHPSkeetBot;

use chillerlan\HTTP\CurlClient;
use chillerlan\HTTP\Psr7\HTTPFactory;
use chillerlan\HTTP\Utils\MessageUtil;
use chillerlan\HTTP\Utils\MimeTypeUtil;
use chillerlan\OAuth\OAuthProviderFactory;
use chillerlan\Settings\SettingsContainerInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use function sleep;
use function sprintf;

/**
 * @link https://docs.bsky.app/docs/advanced-guides/posts
 */
abstract class SkeetBot implements SkeetBotInterface{

	protected SettingsContainerInterface|SkeetBotOptions $options;
	protected LoggerInterface                            $logger;
	protected ClientInterface                            $http;
	protected BlueskyAppPW                               $bluesky;
	protected RequestFactoryInterface                    $requestFactory;
	protected StreamFactoryInterface                     $streamFactory;
	protected UriFactoryInterface                        $uriFactory;
	protected OAuthProviderFactory                       $providerFactory;

	/**
	 * SkeetBot constructor
	 */
	public function __construct(SettingsContainerInterface|SkeetBotOptions $options){
		$this->options = $options;

		// invoke the worker instances
		$this->logger         = $this->initLogger();         // PSR-3
		$this->requestFactory = $this->initRequestFactory(); // PSR-17
		$this->streamFactory  = $this->initStreamFactory();  // PSR-17
		$this->uriFactory     = $this->initUriFactory();     // PSR-17
		$this->http           = $this->initHTTP();           // PSR-18

		$this->providerFactory = new OAuthProviderFactory(
			$this->http,
			$this->requestFactory,
			$this->streamFactory,
			$this->uriFactory,
			$this->logger,
		);

		$this->bluesky = $this->initBluesky(); // acts as PSR-18
	}

	/**
	 * initializes a PSR-3 logger instance
	 */
	protected function initLogger():LoggerInterface{

		// log formatter
		$formatter = (new LineFormatter($this->options->logFormat, $this->options->logDateFormat, true, true))
			->setJsonPrettyPrint(true)
		;

		// a log handler for STDOUT (or STDERR if you prefer)
		$logHandler = (new StreamHandler('php://stdout', $this->options->loglevel))
			->setFormatter($formatter)
		;

		return new Logger('log', [$logHandler]);
	}

	/**
	 * initializes a PSR-17 request factory
	 */
	protected function initRequestFactory():RequestFactoryInterface{
		return new HTTPFactory;
	}

	/**
	 * initializes a PSR-17 stream factory
	 */
	protected function initStreamFactory():StreamFactoryInterface{
		return new HTTPFactory;
	}

	/**
	 * initializes a PSR-17 URI factory
	 */
	protected function initUriFactory():UriFactoryInterface{
		return new HTTPFactory;
	}

	/**
	 * initializes a PSR-18 http client
	 */
	protected function initHTTP():ClientInterface{
		return new CurlClient(new HTTPFactory, $this->options, $this->logger);
	}

	protected function initBluesky():BlueskyAppPW{

		/** @var BlueskyAppPW $bsky */
		$bsky = $this->providerFactory
			->getProvider(BlueskyAppPW::class, $this->options)
		;

		return $bsky;
	}

	public function connect():static{
		$this->bluesky->createSession($this->options->handle, $this->options->appPassword);

		return $this;
	}

	protected function uploadMedia(StreamInterface $media):array|null{

		$response = $this->bluesky->request(
			path   : 'com.atproto.repo.uploadBlob',
			method : 'POST',
			body   : $media,
			headers: [
				'Content-Type' => MimeTypeUtil::getFromContent((string)$media),
				'User-Agent'   => $this->options->user_agent,
			],
		);

		$status = $response->getStatusCode();

		if($status !== 200){
			$this->logger->error(sprintf('image upload error: HTTP/%s', $status));

			return null;
		}

		try{
			$json = MessageUtil::decodeJSON($response, true);
		}
		catch(Throwable $e){
			$this->logger->error(sprintf('image upload response json decode error: %s', $e->getMessage()));

			return null;
		}

		$this->logger->info(sprintf('upload successful, media id: "%s"', $json['blob']['ref']['$link']));

		return $json['blob'];
	}

	protected function submitSkeet(array $body):void{
		$response = null;
		$retry    = 0;

		// try to submit the post
		do{
			try{
				$response = $this->bluesky->request(
					path: 'com.atproto.repo.createRecord',
					method: 'POST',
					body: $body,
					headers: [
						'Content-Type' => 'application/json',
						'User-Agent'   => $this->options->user_agent,
					],
				);
			}
			catch(Throwable $e){
				$this->logger->warning(sprintf('submit post exception: %s (retry #%s)', $e->getMessage(), $retry));
				$retry++;

				continue;
			}

			if($response->getStatusCode() === 200){
				$this->submitSkeetSuccess($response);

				break;
			}

			$this->logger->warning(sprintf('submit post error: %s (retry #%s)', $response->getReasonPhrase(), $retry));

			$retry++;
			// we're not going to hammer, we'll sleep for a bit
			sleep(2);
		}
		while($retry < $this->options->retries);

		// report the failure, a response may or may not have been received
		if($retry >= $this->options->retries){
			$this->submitSkeetFailure($response);
		}

	}

	/**
	 * Optional response processing after post submission (e.g. save toot-id, tick off used dataset...)
	 */
	protected function submitSkeetSuccess(ResponseInterface $response):void{
		// noop
	}

	/**
	 * Optional failed response processing after the maximum number of retries was hit
	 */
	protected function submitSkeetFailure(ResponseInterface|null $response):void{
		// noop
	}

}
