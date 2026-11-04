<?php
/**
 * Class OOBJ_AC_Test_Bot_Check.
 *
 * @package oobj-abandoned-carts
 */

declare( strict_types=1 );

/**
 * A bot check that passes the answer "human", for testing the filter oobj_ac_bot_check.
 */
class OOBJ_AC_Test_Bot_Check implements OOBJ_AC_Bot_Check {

	/**
	 * Whether ready() says it's set up.
	 *
	 * @var bool
	 */
	public $is_ready = true;

	/**
	 * The answers verify() was given.
	 *
	 * @var string[]
	 */
	public $verified = array();

	public function ready(): bool {
		return $this->is_ready;
	}

	public function markup(): string {
		return '<div data-oobj-ac-bot-check="test"></div>';
	}

	public function enqueue(): void {}

	public function verify( string $answer, string $ip ): bool {
		$this->verified[] = $answer;
		return 'human' === $answer;
	}
}
