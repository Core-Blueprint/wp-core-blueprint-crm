<?php
declare(strict_types=1);

$root    = dirname( __DIR__ );
$domain  = 'core-blueprint-crm';
$locales = [ 'nl_NL', 'de_DE', 'fr_FR', 'es_ES', 'it_IT', 'pt_PT' ];

$fail = static function ( string $message ): never {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
};

$decode_php_string = static function ( string $value, string $quote ): string {
	if ( "'" === $quote ) {
		return str_replace( [ "\\\\", "\\'" ], [ "\\", "'" ], $value );
	}
	return stripcslashes( $value );
};

/**
 * @return array{singular:array<string,bool>,plurals:array<string,string>}
 */
$source_messages = static function () use ( $root, $domain, $decode_php_string, $fail ): array {
	$files = [ $root . '/core-blueprint-crm.php' ];
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $iterator as $file ) {
		if ( $file instanceof SplFileInfo && $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}

	$singular = [];
	$plurals  = [];
	$functions = '(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)';

	foreach ( $files as $path ) {
		$code = file_get_contents( $path );
		if ( false === $code ) {
			$fail( 'Could not read source file: ' . $path );
		}

		foreach ( [ "'", '"' ] as $quote ) {
			$q = preg_quote( $quote, '/' );
			$pattern = '/\b' . $functions . '\s*\(\s*' . $q . '((?:\\\\.|[^' . $q . '\\\\])*)' . $q
				. '\s*,\s*' . $q . preg_quote( $domain, '/' ) . $q . '\s*\)/s';
			if ( preg_match_all( $pattern, $code, $matches ) ) {
				foreach ( $matches[1] as $raw ) {
					$msgid = $decode_php_string( $raw, $quote );
					if ( '' !== $msgid ) {
						$singular[ $msgid ] = true;
					}
				}
			}

			$plural_pattern = '/\b_n\s*\(\s*' . $q . '((?:\\\\.|[^' . $q . '\\\\])*)' . $q
				. '\s*,\s*' . $q . '((?:\\\\.|[^' . $q . '\\\\])*)' . $q
				. '\s*,\s*[^,]+,\s*' . $q . preg_quote( $domain, '/' ) . $q . '\s*\)/s';
			if ( preg_match_all( $plural_pattern, $code, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$msgid        = $decode_php_string( $match[1], $quote );
					$msgid_plural = $decode_php_string( $match[2], $quote );
					if ( '' !== $msgid && '' !== $msgid_plural ) {
						$plurals[ $msgid ] = $msgid_plural;
					}
				}
			}
		}
	}

	foreach ( $plurals as $msgid => $_plural ) {
		unset( $singular[ $msgid ] );
	}
	ksort( $singular );
	ksort( $plurals );
	return [ 'singular' => $singular, 'plurals' => $plurals ];
};

$po_unquote = static function ( string $quoted ) use ( $fail ): string {
	$value = json_decode( $quoted, true, 512, JSON_INVALID_UTF8_SUBSTITUTE );
	if ( ! is_string( $value ) ) {
		$fail( 'Invalid quoted PO string: ' . $quoted );
	}
	return $value;
};

/**
 * @return array{entries:array<string,array{plural:?string,translations:array<int,string>}>,header:string,fuzzy:bool}
 */
$parse_po = static function ( string $path ) use ( $po_unquote, $fail ): array {
	$lines = file( $path, FILE_IGNORE_NEW_LINES );
	if ( false === $lines ) {
		$fail( 'Could not read catalog: ' . $path );
	}

	$entries = [];
	$header  = '';
	$fuzzy   = false;
	$current = null;
	$field   = null;
	$index   = 0;

	$flush = static function () use ( &$current, &$entries, &$header, &$fuzzy ): void {
		if ( ! is_array( $current ) || ! array_key_exists( 'msgid', $current ) ) {
			$current = null;
			return;
		}
		$msgid = (string) $current['msgid'];
		if ( '' === $msgid ) {
			$header = (string) ( $current['msgstr'][0] ?? '' );
		} else {
			$entries[ $msgid ] = [
				'plural'       => isset( $current['msgid_plural'] ) ? (string) $current['msgid_plural'] : null,
				'translations' => array_map( 'strval', (array) ( $current['msgstr'] ?? [] ) ),
			];
			if ( ! empty( $current['fuzzy'] ) ) {
				$fuzzy = true;
			}
		}
		$current = null;
	};

	foreach ( $lines as $line ) {
		if ( '' === trim( $line ) ) {
			$flush();
			$field = null;
			continue;
		}
		if ( str_starts_with( $line, '#,' ) && str_contains( $line, 'fuzzy' ) ) {
			if ( null === $current ) {
				$current = [ 'msgstr' => [] ];
			}
			$current['fuzzy'] = true;
			continue;
		}
		if ( str_starts_with( $line, '#' ) ) {
			continue;
		}
		if ( preg_match( '/^msgid\s+(".*")$/', $line, $match ) ) {
			if ( is_array( $current ) && array_key_exists( 'msgid', $current ) ) {
				$flush();
			}
			$current = is_array( $current ) ? $current : [ 'msgstr' => [] ];
			$current['msgid'] = $po_unquote( $match[1] );
			$field = 'msgid';
			continue;
		}
		if ( preg_match( '/^msgid_plural\s+(".*")$/', $line, $match ) ) {
			$current['msgid_plural'] = $po_unquote( $match[1] );
			$field = 'msgid_plural';
			continue;
		}
		if ( preg_match( '/^msgstr(?:\[(\d+)\])?\s+(".*")$/', $line, $match ) ) {
			$index = isset( $match[1] ) && '' !== $match[1] ? (int) $match[1] : 0;
			$current['msgstr'][ $index ] = $po_unquote( $match[2] );
			$field = 'msgstr';
			continue;
		}
		if ( preg_match( '/^(".*")$/', $line, $match ) ) {
			$fragment = $po_unquote( $match[1] );
			if ( 'msgstr' === $field ) {
				$current['msgstr'][ $index ] = (string) ( $current['msgstr'][ $index ] ?? '' ) . $fragment;
			} elseif ( null !== $field ) {
				$current[ $field ] = (string) ( $current[ $field ] ?? '' ) . $fragment;
			}
		}
	}
	$flush();
	ksort( $entries );
	return [ 'entries' => $entries, 'header' => $header, 'fuzzy' => $fuzzy ];
};

/**
 * @return array<string,array{plural:?string,translations:array<int,string>}>
 */
$parse_mo = static function ( string $path ) use ( $fail ): array {
	$data = file_get_contents( $path );
	if ( false === $data || strlen( $data ) < 28 ) {
		$fail( 'Invalid or unreadable MO file: ' . $path );
	}

	$magic_le = unpack( 'Vvalue', substr( $data, 0, 4 ) )['value'];
	$magic_be = unpack( 'Nvalue', substr( $data, 0, 4 ) )['value'];
	if ( 0x950412de === $magic_le ) {
		$read32 = static fn( string $bytes ): int => unpack( 'Vvalue', $bytes )['value'];
	} elseif ( 0x950412de === $magic_be ) {
		$read32 = static fn( string $bytes ): int => unpack( 'Nvalue', $bytes )['value'];
	} else {
		$fail( 'Invalid MO magic: ' . $path );
	}

	$count      = $read32( substr( $data, 8, 4 ) );
	$orig_table = $read32( substr( $data, 12, 4 ) );
	$tran_table = $read32( substr( $data, 16, 4 ) );
	$entries    = [];

	for ( $i = 0; $i < $count; ++$i ) {
		$orig_len = $read32( substr( $data, $orig_table + ( $i * 8 ), 4 ) );
		$orig_off = $read32( substr( $data, $orig_table + ( $i * 8 ) + 4, 4 ) );
		$tran_len = $read32( substr( $data, $tran_table + ( $i * 8 ), 4 ) );
		$tran_off = $read32( substr( $data, $tran_table + ( $i * 8 ) + 4, 4 ) );
		$original = substr( $data, $orig_off, $orig_len );
		$translated = substr( $data, $tran_off, $tran_len );
		if ( '' === $original ) {
			continue;
		}
		$orig_parts = explode( "\0", $original );
		$tran_parts = explode( "\0", $translated );
		$entries[ $orig_parts[0] ] = [
			'plural'       => $orig_parts[1] ?? null,
			'translations' => array_values( $tran_parts ),
		];
	}
	ksort( $entries );
	return $entries;
};

$placeholders = static function ( string $value ): array {
	preg_match_all( '/%(?:\d+\$)?[bcdeEfFgGosuxX]/', $value, $matches );
	$items = $matches[0] ?? [];
	sort( $items );
	return $items;
};

$source = $source_messages();
$pot_path = $root . '/languages/core-blueprint-crm.pot';
$pot = $parse_po( $pot_path );

if ( $pot['fuzzy'] ) {
	$fail( 'POT must not be fuzzy.' );
}

$expected_keys = array_keys( $source['singular'] + $source['plurals'] );
sort( $expected_keys );
$pot_keys = array_keys( $pot['entries'] );
sort( $pot_keys );
if ( $expected_keys !== $pot_keys ) {
	$missing = array_values( array_diff( $expected_keys, $pot_keys ) );
	$stale   = array_values( array_diff( $pot_keys, $expected_keys ) );
	$fail( 'POT/source mismatch. Missing: ' . implode( ' | ', $missing ) . '; stale: ' . implode( ' | ', $stale ) );
}

foreach ( $source['plurals'] as $msgid => $plural ) {
	if ( ! isset( $pot['entries'][ $msgid ] ) || $plural !== $pot['entries'][ $msgid ]['plural'] ) {
		$fail( 'POT plural mismatch for: ' . $msgid );
	}
}

$unsafe = [ 'Add %s', 'Edit %s', 'New %s', 'View %s', 'Search %s', 'No %s found.' ];
foreach ( $unsafe as $msgid ) {
	if ( isset( $source['singular'][ $msgid ] ) ) {
		$fail( 'Unsafe compositional UI string returned to source: ' . $msgid );
	}
}

foreach ( $locales as $locale ) {
	$po_path = $root . '/languages/core-blueprint-crm-' . $locale . '.po';
	$mo_path = $root . '/languages/core-blueprint-crm-' . $locale . '.mo';
	if ( ! is_file( $po_path ) || ! is_file( $mo_path ) ) {
		$fail( 'Missing PO/MO pair for ' . $locale );
	}

	$po = $parse_po( $po_path );
	if ( $po['fuzzy'] ) {
		$fail( 'Fuzzy translation found in ' . $locale );
	}
	if ( ! str_contains( $po['header'], "Language: {$locale}\n" ) ) {
		$fail( 'Incorrect or missing Language header for ' . $locale );
	}
	if ( ! str_contains( $po['header'], 'Plural-Forms:' ) ) {
		$fail( 'Missing Plural-Forms header for ' . $locale );
	}

	$po_keys = array_keys( $po['entries'] );
	sort( $po_keys );
	if ( $pot_keys !== $po_keys ) {
		$missing = array_values( array_diff( $pot_keys, $po_keys ) );
		$stale   = array_values( array_diff( $po_keys, $pot_keys ) );
		$fail( $locale . ' catalog mismatch. Missing: ' . implode( ' | ', $missing ) . '; stale: ' . implode( ' | ', $stale ) );
	}

	foreach ( $po['entries'] as $msgid => $entry ) {
		$is_plural = null !== $entry['plural'];
		$required_forms = $is_plural ? 2 : 1;
		if ( count( $entry['translations'] ) < $required_forms ) {
			$fail( $locale . ' is missing plural forms for: ' . $msgid );
		}
		foreach ( $entry['translations'] as $index => $translation ) {
			if ( '' === trim( $translation ) ) {
				$fail( $locale . ' has an empty translation for: ' . $msgid );
			}
			$source_for_form = $is_plural && $index > 0 ? (string) $entry['plural'] : $msgid;
			if ( $placeholders( $source_for_form ) !== $placeholders( $translation ) ) {
				$fail( $locale . ' placeholder mismatch for: ' . $msgid );
			}
		}
	}

	$mo = $parse_mo( $mo_path );
	if ( $po_keys !== array_keys( $mo ) ) {
		$fail( $locale . ' MO keys do not match its PO catalog.' );
	}
	foreach ( $po['entries'] as $msgid => $entry ) {
		$mo_entry = $mo[ $msgid ] ?? null;
		if ( ! is_array( $mo_entry ) || $entry['plural'] !== $mo_entry['plural'] || array_values( $entry['translations'] ) !== array_values( $mo_entry['translations'] ) ) {
			$fail( $locale . ' MO is stale relative to PO for: ' . $msgid );
		}
	}
}

echo "CRM localization conformance passed.\n";
