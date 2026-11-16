<?php
/**
 * Line item group handler.
 *
 * @package WPTravelEngine
 * @since 6.9.0
 */

namespace WPTravelEngine\Core\Booking\LineItems;

use WPTravelEngine\Helpers\CartInfoParser;
use WPTravelEngine\Utilities\ArrayUtility;
use WPTravelEngine\Core\Booking\SaveBooking;
use WPTravelEngine\Utilities\PaymentCalculator;

/**
 * Base line item group; subclasses override where they differ.
 */
abstract class AbstractBase {

	/**
	 * Group key.
	 *
	 * @var string
	 */
	protected string $key;

	/**
	 * Calculator at the current cart's currency; set by bind().
	 *
	 * @var PaymentCalculator
	 */
	protected PaymentCalculator $calc;

	/**
	 * The save in progress; set by bind(). Its cart is read through cart(), never held.
	 *
	 * @var SaveBooking
	 */
	protected SaveBooking $save;

	/**
	 * Keys of the rows the last merge added and updated.
	 *
	 * @var array{added: array<int, string>, updated: array<int, string>}
	 */
	protected array $changes = array(
		'added'   => array(),
		'updated' => array(),
	);

	/**
	 * Labels of the rows the last merge added, updated and removed, for the activity log.
	 *
	 * @var array{added: array<int, string>, updated: array<int, string>, removed: array<int, string>}
	 */
	protected array $names = array(
		'added'   => array(),
		'updated' => array(),
		'removed' => array(),
	);

	/**
	 * Constructor.
	 *
	 * @param string $key Group key.
	 */
	public function __construct( string $key ) {
		$this->key = $key;
	}

	/**
	 * Group key.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Field rows are found by.
	 *
	 * @return string
	 */
	public function id_key(): string {
		return 'id';
	}

	/**
	 * What tells one row from another; empty when the row is not stored yet.
	 *
	 * @param array<string, mixed> $row Stored or payload row.
	 * @return string
	 */
	public function row_key( array $row ): string {
		return (string) ( $row[ $this->id_key() ] ?? $row['id'] ?? '' );
	}

	/**
	 * Stored rows, filled in where older bookings left them short.
	 *
	 * @param array<int, array<string, mixed>> $lines Stored rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function normalize( array $lines ): array {
		return $lines;
	}

	/**
	 * Key under `subtotal_reservations`; null when the group keeps none.
	 *
	 * @return string|null
	 */
	public function reservation_key(): ?string {
		return null;
	}

	/**
	 * Cart item class a row is rebuilt with; null when the group sets none.
	 *
	 * @return string|null
	 */
	public function class_name(): ?string {
		return null;
	}

	/**
	 * Whether quantity follows the party size.
	 *
	 * @return bool
	 */
	public function is_per_traveler(): bool {
		return false;
	}

	/**
	 * Points the handler at the save in progress, and its calculator at the cart's currency.
	 *
	 * Handlers are shared across the request, so each entry point binds before anything reads.
	 *
	 * @param SaveBooking $save The save in progress.
	 * @return void
	 */
	protected function bind( SaveBooking $save ): void {
		if ( isset( $this->save ) && $this->save === $save ) {
			return;
		}

		$this->save = $save;
		$this->calc = PaymentCalculator::for( $save->get_cart_info()->get_currency() );
	}

	/**
	 * The save's cart as it stands now.
	 *
	 * Read through the save every time: it replaces its cart object when it writes one back, so a
	 * held copy would go on writing to the old one.
	 *
	 * @return CartInfoParser
	 */
	protected function cart(): CartInfoParser {
		return $this->save->get_cart_info();
	}

	/**
	 * Writes payload rows and their reservations into the save's cart.
	 *
	 * @param SaveBooking                      $save The save in progress.
	 * @param string|int                       $key  Cart item key.
	 * @param array<int, array<string, mixed>> $rows Payload rows.
	 * @return array<int, int> Added, removed, updated.
	 */
	public function write( SaveBooking $save, $key, array $rows ): array {
		$this->bind( $save );

		list( $lines, $counts ) = $this->merge( $this->lines( $key ), $rows );

		if ( array_sum( $counts ) ) {
			$this->store( $this->path( $key ), $lines );
		}

		$reservation_key = $this->reservation_key();

		if ( null !== $reservation_key ) {
			$path = "items.{$key}.subtotal_reservations.{$reservation_key}";

			$this->store( $path, $this->reserve( ArrayUtility::to_array( $this->cart()->get( $path, array() ) ), $lines ) );
		}

		return $counts;
	}

	/**
	 * Keys of the rows the last write added and updated, as `row_key()` reads them.
	 *
	 * @return array{added: array<int, string>, updated: array<int, string>}
	 */
	public function changes(): array {
		return $this->changes;
	}

	/**
	 * Labels of the rows the last write added, updated and removed.
	 *
	 * @return array{added: array<int, string>, updated: array<int, string>, removed: array<int, string>}
	 */
	public function names(): array {
		return $this->names;
	}

	/**
	 * Payload rows merged over stored ones; zero quantity removes.
	 *
	 * @param array<int, array<string, mixed>> $lines Stored rows.
	 * @param array<int, array<string, mixed>> $rows  Payload rows.
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, int>} Rows, and added, removed, updated.
	 */
	protected function merge( array $lines, array $rows ): array {
		$added   = 0;
		$removed = 0;
		$updated = 0;

		$this->changes = array(
			'added'   => array(),
			'updated' => array(),
		);
		$this->names   = array(
			'added'   => array(),
			'updated' => array(),
			'removed' => array(),
		);

		foreach ( $rows as $row ) {
			$at     = $this->find( $lines, $row );
			$is_new = null === $at;
			$line   = $this->apply( $is_new ? $this->new_line( $row ) : $lines[ $at ], $row, $is_new );

			if ( (int) $line['quantity'] <= 0 ) {
				if ( ! $is_new ) {
					$this->names['removed'][] = wptravelengine_decode_entity( (string) ( $lines[ $at ]['label'] ?? '' ) );
					unset( $lines[ $at ] );
					++$removed;
				}

				continue;
			}

			// Keyed off the merged row: a new one only has its id once `new_line()` gives it one.
			$this->changes[ $is_new ? 'added' : 'updated' ][] = $this->row_key( $line );
			$this->names[ $is_new ? 'added' : 'updated' ][]   = wptravelengine_decode_entity( (string) ( $line['label'] ?? '' ) );

			if ( $is_new ) {
				$lines[] = $line;
				++$added;
			} else {
				$lines[ $at ] = $line;
				++$updated;
			}
		}

		return array( array_values( $lines ), array( $added, $removed, $updated ) );
	}

	/**
	 * Cart path of the group's rows.
	 *
	 * @param string|int $key Cart item key.
	 * @return string
	 */
	protected function path( $key ): string {
		return "items.{$key}.line_items.{$this->key}";
	}

	/**
	 * The group's stored rows.
	 *
	 * @param string|int $key Cart item key.
	 * @return array<int, array<string, mixed>>
	 */
	protected function lines( $key ): array {
		return $this->normalize( ArrayUtility::to_array( $this->cart()->get( $this->path( $key ), array() ) ) );
	}

	/**
	 * A money figure from the payload, never below zero.
	 *
	 * @param mixed $value Payload value.
	 * @return float
	 */
	protected function amount( $value ): float {
		return max( 0, (float) $value );
	}

	/**
	 * A flag as the cart stores it.
	 *
	 * @param mixed $value Payload value: bool, `'yes'`, `1` and the like.
	 * @return string `'yes'` or `'no'`.
	 */
	protected function to_yes_no( $value ): string {
		return wptravelengine_replace( wptravelengine_toggled( $value ), true, 'yes', 'no' );
	}

	/**
	 * Stores rows at a path; empty removes it.
	 *
	 * @param string                           $path Dotted cart path.
	 * @param array<int, array<string, mixed>> $rows Rows to store.
	 * @return void
	 */
	protected function store( string $path, array $rows ): void {
		if ( $rows ) {
			$this->cart()->set( $path, array_values( $rows ) );
		} else {
			$this->cart()->remove( $path );
		}
	}

	/**
	 * Reservations with quantities taken from the rows.
	 *
	 * @param array<int, array<string, mixed>> $held  Reservations as stored.
	 * @param array<int, array<string, mixed>> $lines The group's rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function reserve( array $held, array $lines ): array {
		$by_id = array_column( array_map( array( ArrayUtility::class, 'to_array' ), $held ), null, 'id' );

		return array_map(
			function ( $line ) use ( $by_id ) {
				$id = $line[ $this->id_key() ] ?? null;

				return array_merge(
					$by_id[ (string) $id ] ?? array( 'id' => $id ),
					array( 'quantity' => (int) ( $line['quantity'] ?? 0 ) )
				);
			},
			$lines
		);
	}

	/**
	 * Position of the stored row the payload row addresses.
	 *
	 * @param array<int, array<string, mixed>> $lines Stored rows.
	 * @param array<string, mixed>             $row   Payload row.
	 * @return int|null
	 */
	public function find( array $lines, array $row ): ?int {
		$key = $this->row_key( $row );

		if ( '' === $key ) {
			return null;
		}

		$at = array_search( $key, array_map( array( $this, 'row_key' ), $lines ), true );

		return false === $at ? null : $at;
	}

	/**
	 * Blank new row; hand-typed rows get a `manual_` id.
	 *
	 * @param array<string, mixed> $row Payload row.
	 * @return array<string, mixed>
	 */
	public function new_line( array $row ): array {
		$id = sanitize_text_field( (string) ( $row['id'] ?? '' ) );

		// A post ID stays an int; sanitizing after the cast would turn it back into a string.
		if ( is_numeric( $id ) ) {
			$id = (int) $id;
		}

		return array(
			$this->id_key() => '' === $id ? 'manual_' . wp_generate_uuid4() : $id,
			'label'         => '',
			'quantity'      => 0,
			'price'         => 0,
		);
	}

	/**
	 * Payload row merged over a stored one, totalled.
	 *
	 * @param array<string, mixed> $line   Stored or blank row.
	 * @param array<string, mixed> $row    Payload row.
	 * @param bool                 $is_new Whether the row is new.
	 * @return array<string, mixed>
	 */
	public function apply( array $line, array $row, bool $is_new ): array {
		if ( isset( $row['label'] ) ) {
			$line['label'] = (string) $row['label'];
		}

		if ( isset( $row['quantity'] ) ) {
			$line['quantity'] = max( 0, (int) $row['quantity'] );
		}

		if ( isset( $row['price'] ) ) {
			$line['price'] = $this->amount( $row['price'] );
		}

		if ( $is_new || isset( $row['is_manual'] ) ) {
			$line['is_manual'] = $this->to_yes_no( $row['is_manual'] ?? false );
		}

		if ( isset( $row['traveller_id'] ) ) {
			$line['traveller_id'] = max( 0, (int) $row['traveller_id'] );
		}

		if ( empty( $line['_class_name'] ) && null !== $this->class_name() ) {
			$line['_class_name'] = $this->class_name();
		}

		$line['total'] = (float) $this->calc->multiply( (string) ( $line['quantity'] ?? 0 ), (string) ( $line['price'] ?? 0 ) );

		return $line;
	}

	/**
	 * Stored row in REST shape.
	 *
	 * @param array<string, mixed> $row Stored row.
	 * @return array<string, mixed>
	 */
	public function to_resource( array $row ): array {
		return array(
			'id'        => $row[ $this->id_key() ] ?? $row['id'] ?? 0,
			'label'     => wptravelengine_decode_entity( $row['label'] ?? '' ),
			'quantity'  => (int) ( $row['quantity'] ?? 0 ),
			'price'     => (float) ( $row['price'] ?? 0 ),
			'total'     => (float) ( $row['total'] ?? 0 ),
			'is_manual' => wptravelengine_toggled( $row['is_manual'] ?? 'no' ),
		);
	}
}
