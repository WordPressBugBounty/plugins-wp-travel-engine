<?php
/**
 * Admin settings builder.
 *
 * @package WPTravelEngine
 * @since 6.2.0
 */

namespace WPTravelEngine\Builders;
class AdminSettings {

	public function tabs(): array {
		$tab_dir = __DIR__ . '/admin-settings';

		$iterator = new \DirectoryIterator( $tab_dir );

		$tabs = array();
		foreach ( $iterator as $directory ) {
			if ( $directory->isDot() || $directory->isDir() ) {
				continue;
			}

			$tab_settings = include $directory->getPathname();

			if ( isset( $tab_settings[ 'sub_tabs' ] ) ) {
				$sub_tabs = $tab_settings[ 'sub_tabs' ];
				if ( is_string( $sub_tabs ) && is_dir( $sub_tabs ) ) {
					$tab_settings[ 'sub_tabs' ] = $this->get_sub_tabs( $sub_tabs );
				}
			}


			$tabs[] = $tab_settings;

		}

		usort( $tabs, function ( $a, $b ) {
			return $a[ 'order' ] - $b[ 'order' ];
		} );

		return $tabs;
	}

	/**
	 * Get sub tabs.
	 *
	 * @param string $sub_tabs Sub tabs directory.
	 *
	 * @return array
	 */
	public function get_sub_tabs( string $sub_tabs ): array {
		$iterator = new \DirectoryIterator( $sub_tabs );

		$tabs = array();
		foreach ( $iterator as $directory ) {
			if ( $directory->isDot() || ! $directory->isFile() ) {
				continue;
			}
			$tab_settings = include $directory->getPathname();

			$tabs[] = $tab_settings;

		}

		usort( $tabs, function ( $a, $b ) {
			//check if order is set.
			if ( ! isset( $a[ 'order' ] ) || ! isset( $b[ 'order' ] ) ) {
				return 100;
			}
				return $a[ 'order' ] - $b[ 'order' ];

		} );

		$tabs = array_values( array_filter( $tabs, function( $tab ) {
			return !empty( $tab );
		} ) );

        return $tabs;
	}
}
