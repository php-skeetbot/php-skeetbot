<?php
/**
 * Class SkeetBotOptions
 *
 * @created      26.10.2024
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2024 smiley
 * @license      MIT
 */
declare(strict_types=1);

namespace PHPSkeetBot\PHPSkeetBot;

use chillerlan\HTTP\HTTPOptionsTrait;
use chillerlan\OAuth\OAuthOptionsTrait;
use chillerlan\Settings\SettingsContainerAbstract;
use chillerlan\Utilities\Directory;
use InvalidArgumentException;
use Psr\Log\LogLevel;
use function in_array;
use function rtrim;
use function sprintf;
use function strtolower;

/**
 * @property string      $instance
 * @property string      $handle
 * @property string      $appPassword
 * @property string      $loglevel
 * @property string|null $buildDir
 * @property string|null $dataDir
 * @property string      $logFormat
 * @property string      $logDateFormat
 */
class SkeetBotOptions extends SettingsContainerAbstract{
	use HTTPOptionsTrait, OAuthOptionsTrait;

	/**
	 * The atproto home instance of this bot, e.g. https://bsky.social
	 *
	 * (currently ignored)
	 */
	protected string $instance = 'https://bsky.social';

	/**
	 * The account handle or user DID
	 */
	protected string $handle = '';

	/**
	 * The app password
	 *
	 * @link https://bsky.app/settings/app-passwords
	 */
	protected string $appPassword = '';

	/**
	 * The log level for the internal logger instance
	 *
	 * @see \Psr\Log\LogLevel
	 */
	protected string $loglevel = LogLevel::INFO;

	/**
	 * An optional path to a build directory
	 */
	protected string|null $buildDir = null;

	/**
	 * An optional path to a data directory
	 */
	protected string|null $dataDir = null;

	/**
	 * The log format string
	 *
	 * @see \Monolog\Formatter\LineFormatter
	 * @link https://github.com/Seldaek/monolog/blob/main/doc/01-usage.md#customizing-the-log-format
	 */
	protected string $logFormat = "[%datetime%] %channel%.%level_name%: %message%\n";

	/**
	 * @see \DateTimeInterface::format()
	 * @link https://www.php.net/manual/en/datetime.format.php
	 */
	protected string $logDateFormat = 'Y-m-d H:i:s';

	/**
	 * Sets the Mastodon instance URL
	 */
	protected function set_instance(string $instance):void{
		$this->instance = rtrim($instance, '/');
	}

	/**
	 * Checks and sets the log level
	 */
	protected function set_loglevel(string $loglevel):void{
		$loglevel = strtolower($loglevel);

		if(!in_array($loglevel, ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'], true)){
			throw new InvalidArgumentException(sprintf('invalid loglevel: "%s"', $loglevel));
		}

		$this->loglevel = $loglevel;
	}

	/**
	 * Sets the build directory - creates it if it doesn't exist
	 */
	protected function set_buildDir(string $buildDir):void{
		$this->buildDir = Directory::create($buildDir);
	}

	/**
	 * Sets the data directory - creates it if it doesn't exist
	 */
	protected function set_dataDir(string $dataDir):void{
		$this->dataDir = Directory::create($dataDir);
	}

}
