<?php
/**
 * Interface SkeetBotInterface
 *
 * @created      26.10.2024
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2024 smiley
 * @license      MIT
 */
declare(strict_types=1);

namespace PHPSkeetBot\PHPSkeetBot;

/**
 *
 */
interface SkeetBotInterface{

	/**
	 * Creates and submits a new skeet generated from the given dataset
	 *
	 * This method shall be called from the actions-runner (or cron job)
	 */
	public function post():static;

}
