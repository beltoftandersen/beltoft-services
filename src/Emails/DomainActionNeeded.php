<?php
/** Customer email for a pending registry action. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class DomainActionNeeded extends ServiceEmail {

	protected const ID = 'beltoft_services_domain_action_needed';
}
