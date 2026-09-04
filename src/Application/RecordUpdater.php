<?php
declare(strict_types=1);

namespace CB\CRM\Application;

use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Entity;
use CB\CRM\Content\Meta;
use CB\CRM\Content\PostTypes;
use CB\CRM\Content\RecordStatus;
use CB\CRM\Governance;
use CB\CRM\Repository\Activity;
use CB\CRM\Repository\Addresses;
use CB\CRM\Repository\ContactMethods;
use CB\CRM\Repository\Names;
use CB\CRM\Repository\Organizations;
use CB\CRM\Repository\Services;

defined( 'ABSPATH' ) || exit;

final class RecordUpdater {
	/** @param array<string,mixed> $input
	 *  @return array{record_id:int,owner_type:string,updated_areas:string[]}|\WP_Error
	 */
	public static function update_contact( int $contact_id, array $input ): array|\WP_Error {
		return self::update( Entity::CONTACT, $contact_id, $input );
	}

	/** @param array<string,mixed> $input
	 *  @return array{record_id:int,owner_type:string,updated_areas:string[]}|\WP_Error
	 */
	public static function update_organization( int $organization_id, array $input ): array|\WP_Error {
		return self::update( Entity::ORGANIZATION, $organization_id, $input );
	}

	/** @param array<string,mixed> $input
	 *  @return array{record_id:int,owner_type:string,updated_areas:string[]}|\WP_Error
	 */
	private static function update( string $owner_type, int $record_id, array $input ): array|\WP_Error {
		$expected_type = Entity::post_type_for_owner( $owner_type );
		$post          = $record_id > 0 ? get_post( $record_id ) : null;
		if ( ! $post instanceof \WP_Post || $expected_type !== $post->post_type || 'trash' === $post->post_status ) {
			return new \WP_Error( 'crm_record_not_found' );
		}

		$areas    = [];
		$failures = [];
		if ( Entity::CONTACT === $owner_type && array_key_exists( 'wp_user_id', $input ) ) {
			$stored_user_id = ContactIdentity::linked_user_id( $record_id );
			if ( ! is_scalar( $input['wp_user_id'] ) || ! is_numeric( $input['wp_user_id'] ) ) {
				$input['wp_user_id'] = $stored_user_id;
				$failures[] = 'wordpress_user';
			} else {
				$user_id = absint( $input['wp_user_id'] );
				if ( $user_id > 0 && ! get_userdata( $user_id ) ) {
					$input['wp_user_id'] = 0;
					$failures[] = 'wordpress_user';
				} elseif ( $user_id > 0 && ! ContactIdentity::can_link_user_to_contact( $user_id, $record_id ) ) {
					$input['wp_user_id'] = $stored_user_id;
					$failures[] = 'wordpress_user';
				}
			}
		}
		if ( self::update_details( $owner_type, $record_id, $input, $failures ) ) {
			$areas[] = 'details';
			Governance::record_data_updated( $owner_type, $record_id, 'details' );
		}

		if ( array_key_exists( 'contact_methods', $input ) ) {
			if ( ! is_array( $input['contact_methods'] ) ) {
				$failures[] = 'contact_methods';
			} elseif ( ContactMethods::replace( $owner_type, $record_id, $input['contact_methods'] ) ) {
				$areas[] = 'contact_methods';
				Governance::record_data_updated( $owner_type, $record_id, 'contact_methods' );
			} else { $failures[] = 'contact_methods'; }
		}
		if ( array_key_exists( 'addresses', $input ) ) {
			if ( ! is_array( $input['addresses'] ) ) {
				$failures[] = 'addresses';
			} elseif ( Addresses::replace( $owner_type, $record_id, $input['addresses'] ) ) {
				$areas[] = 'addresses';
				Governance::record_data_updated( $owner_type, $record_id, 'addresses' );
			} else { $failures[] = 'addresses'; }
		}
		if ( Entity::CONTACT === $owner_type && array_key_exists( 'names', $input ) ) {
			if ( ! is_array( $input['names'] ) ) {
				$failures[] = 'names';
			} elseif ( Names::replace( $owner_type, $record_id, $input['names'] ) ) {
				$areas[] = 'names';
				Governance::record_data_updated( $owner_type, $record_id, 'names' );
			} else { $failures[] = 'names'; }
		}
		if ( Entity::CONTACT === $owner_type && array_key_exists( 'organizations', $input ) ) {
			if ( ! is_array( $input['organizations'] ) ) {
				$failures[] = 'organizations';
			} elseif ( Organizations::replace_for_contact( $record_id, $input['organizations'] ) ) {
				$areas[] = 'organizations';
				Governance::record_data_updated( $owner_type, $record_id, 'organizations' );
			} else { $failures[] = 'organizations'; }
		}
		if ( array_key_exists( 'services', $input ) ) {
			if ( ! is_array( $input['services'] ) ) {
				$failures[] = 'services';
			} elseif ( Services::replace( $owner_type, $record_id, $input['services'] ) ) {
				$areas[] = 'services';
				Governance::record_data_updated( $owner_type, $record_id, 'services' );
			} else { $failures[] = 'services'; }
		}
		if ( array_key_exists( 'tags', $input ) ) {
			if ( ! is_array( $input['tags'] ) && ! is_scalar( $input['tags'] ) ) {
				$failures[] = 'tags';
			} else {
				$terms = self::terms( $input['tags'] );
				$result = wp_set_object_terms( $record_id, $terms, PostTypes::TAG, false );
				if ( is_wp_error( $result ) ) {
					$failures[] = 'tags';
				} else {
					$areas[] = 'tags';
					Governance::record_data_updated( $owner_type, $record_id, 'tags' );
				}
			}
		}

		$areas = array_values( array_unique( $areas ) );
		if ( [] !== $areas ) {
			Activity::record(
				$owner_type,
				$record_id,
				'record_updated',
				__( 'CRM record details updated', 'core-blueprint-crm' ),
				'crm',
				get_current_user_id(),
				'post',
				(string) $record_id,
				[ 'areas' => implode( ',', $areas ) ]
			);
		}

		if ( [] !== $failures ) {
			return new \WP_Error( 'crm_update_failed', '', [
				'record_id'     => $record_id,
				'owner_type'    => $owner_type,
				'updated_areas' => $areas,
				'failed_areas'  => array_values( array_unique( $failures ) ),
			] );
		}

		return [ 'record_id' => $record_id, 'owner_type' => $owner_type, 'updated_areas' => $areas ];
	}

	/** @param array<string,mixed> $input */
	private static function update_details( string $owner_type, int $record_id, array $input, array &$failures ): bool {
		$changed = false;
		if ( array_key_exists( 'title', $input ) ) {
			if ( ! is_scalar( $input['title'] ) ) {
				$failures[] = 'details';
			} else {
				$title = sanitize_text_field( (string) $input['title'] );
				if ( get_the_title( $record_id ) !== $title ) {
					$result = wp_update_post( [ 'ID' => $record_id, 'post_title' => $title ], true );
					if ( is_wp_error( $result ) ) { $failures[] = 'details'; } else { $changed = true; }
				}
			}
		}
		if ( array_key_exists( 'status', $input ) ) {
			$status = is_scalar( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : '';
			if ( ! in_array( $status, RecordStatus::VALUES, true ) ) {
				$failures[] = 'details';
			} else {
				$changed = self::update_meta_if_changed( $record_id, Meta::STATUS, $status ) || $changed;
			}
		}

		if ( Entity::CONTACT === $owner_type ) {
			foreach ( [ 'first_name' => Meta::FIRST_NAME, 'last_name' => Meta::LAST_NAME, 'job_title' => Meta::JOB_TITLE ] as $key => $meta_key ) {
				if ( ! array_key_exists( $key, $input ) ) {
					continue;
				}
				if ( ! is_scalar( $input[ $key ] ) ) {
					$failures[] = 'details';
					continue;
				}
				$value = sanitize_text_field( (string) $input[ $key ] );
				$changed = self::update_meta_if_changed( $record_id, $meta_key, $value ) || $changed;
			}
			if ( array_key_exists( 'wp_user_id', $input ) ) {
				$user_id = absint( $input['wp_user_id'] );
				$changed = self::update_meta_if_changed( $record_id, Meta::WP_USER_ID, $user_id ) || $changed;
			}
			if ( array_key_exists( 'email_mode', $input ) || array_key_exists( 'wp_user_id', $input ) ) {
				$user_id = array_key_exists( 'wp_user_id', $input ) ? absint( $input['wp_user_id'] ) : ContactIdentity::linked_user_id( $record_id );
				$stored_mode = sanitize_key( (string) get_post_meta( $record_id, Meta::EMAIL_MODE, true ) );
				$mode = $stored_mode;
				if ( array_key_exists( 'email_mode', $input ) ) {
					$candidate = is_scalar( $input['email_mode'] ) ? sanitize_key( (string) $input['email_mode'] ) : '';
					if ( ! in_array( $candidate, [ ContactIdentity::EMAIL_CRM, ContactIdentity::EMAIL_WP ], true ) ) {
						$failures[] = 'details';
					} else {
						$mode = $candidate;
					}
				}
				if ( ContactIdentity::EMAIL_WP === $mode && 0 === $user_id ) { $mode = ContactIdentity::EMAIL_CRM; }
				$changed = self::update_meta_if_changed( $record_id, Meta::EMAIL_MODE, $mode ) || $changed;
			}
		}
		if ( Entity::ORGANIZATION === $owner_type && array_key_exists( 'legal_name', $input ) ) {
			if ( ! is_scalar( $input['legal_name'] ) ) {
				$failures[] = 'details';
			} else {
				$value = sanitize_text_field( (string) $input['legal_name'] );
				$changed = self::update_meta_if_changed( $record_id, Meta::LEGAL_NAME, $value ) || $changed;
			}
		}
		return $changed;
	}

	private static function update_meta_if_changed( int $record_id, string $key, mixed $value ): bool {
		$before = get_post_meta( $record_id, $key, true );
		if ( (string) $before === (string) $value ) { return false; }
		update_post_meta( $record_id, $key, $value );
		return true;
	}

	/** @return array<int,int|string> */
	private static function terms( mixed $raw ): array {
		$values = is_array( $raw ) ? array_slice( $raw, 0, 50 ) : ( is_scalar( $raw ) ? [ $raw ] : [] );
		$terms = [];
		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) { continue; }
			$string = trim( (string) $value );
			if ( '' === $string ) { continue; }
			$terms[] = ctype_digit( $string ) ? absint( $string ) : sanitize_title( $string );
		}
		return array_values( array_unique( $terms, SORT_REGULAR ) );
	}
}
