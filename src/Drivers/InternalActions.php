<?php
/** Actions queued by trusted plugin hooks, never exposed in customer REST. @package BeltoftServices */
namespace BeltoftServices\Drivers;

defined( 'ABSPATH' ) || exit;

interface InternalActions {
	/** @return string[] Action names. */
	public function internal_actions(): array;
}
