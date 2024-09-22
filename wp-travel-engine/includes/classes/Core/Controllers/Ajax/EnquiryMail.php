<?php
/**
 * Enquiry Mail controller.
 *
 * @package WPTravelEngine/Core/Controllers
 * @since 6.0.0
 */

namespace WPTravelEngine\Core\Controllers\Ajax;

use WPTravelEngine\Abstracts\AjaxController;
use WPTravelEngine\Core\Models\Settings\PluginSettings;

/**
 * Handles enquiry related ajax mail request.
 */
class EnquiryMail extends AjaxController {

	const NONCE_KEY    = 'nonce';
	const NONCE_ACTION = 'wte_enquiry_send_mail';
	const ACTION       = 'wte_enquiry_send_mail';

	/**
	 * Process Request.
	 * Sends mail to subscriber and admin.
	 *
	 * @since 3.0.0
	 */
	protected function process_request() {
		// phpcs:disable
		if ( 'wte_enquiry_send_mail' !== ( $this->request->get_param( 'action' ) ?? null ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid Action', 'wp-travel-engine' ) ) );
			exit;
		}

		$formdata = wte_clean( wp_unslash( $this->request->get_params() ) );
		// phpcs:enable

		$email           = ( $formdata['enquiry_email'] ) ?? false;
		$enquiry_message = ( $formdata['enquiry_message'] ) ?? false;
		$name            = ( $formdata['enquiry_name'] ) ?? false;

		$cust_enquiry_subject = $formdata['enquiry_subject'] ?? false;
		if ( ! $email || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please provide a valid email', 'wp-travel-engine' ) ) );
			die;
		}

		$validation_check = apply_filters( 'wp_travel_engine_enquiry_validation_check', array( 'status' => true ) );

		if ( false === ( $validation_check['status'] ?? null ) ) {
			$result['type']    = 'error';
			$result['message'] = $validation_check['message'];
			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				wp_send_json_error(
					array(
						'message' => $validation_check['message'],
					)
				);
				die;
			}
		}

		// $wp_travel_engine_settings = get_option( 'wp_travel_engine_settings', true );
		$wp_travel_engine_settings = ( new PluginSettings() )->get();
		$postid                    = get_post( $formdata['package_id'] );
		$slug                      = $postid->post_title;
		$url                       = '<a href=' . esc_url( get_permalink( $postid ) ) . '>' . esc_attr( $slug ) . '</a>';
		$subject                   = $wp_travel_engine_settings['query_subject'] ?? __( 'Enquiry received', 'wp-travel-engine' );

		if ( $cust_enquiry_subject ) {
			$subject = $cust_enquiry_subject;
		}

		$enquirer_tags         = array( '{enquirer_name}', '{enquirer_email}' );
		$enquirer_replace_tags = array( $name, $email );
		$subject               = str_replace( $enquirer_tags, $enquirer_replace_tags, $subject );
		$admin_email           = get_option( 'admin_email' );

		if ( ! empty( $wp_travel_engine_settings['email']['enquiry_emailaddress'] ) ) {
			$enquiry_emailaddress = $wp_travel_engine_settings['email']['enquiry_emailaddress'];

			$explode_email = explode( ',', $enquiry_emailaddress );
			$to            = array_map( 'sanitize_email', $explode_email );

		} else {
			$to = sanitize_email( $admin_email );
		}

		$sender = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) . ' <' . wte_get_from_email() . '>';

		$headers  = 'MIME-Version: 1.0' . "\r\n";
		$headers .= 'Content-type: text/html; charset=UTF-8' . "\r\n";
		$headers .= 'From: ' . $sender . "\r\n";
		$headers .= 'Reply-To: ' . $email . "\r\n";
		// Create email headers.
		$headers .= 'X-Mailer: PHP/' . phpversion();

		$remove_keys = array(
			'package_id',
			'redirect-url',
			'enquiry_confirmation[]',
			'enquiry_confirmation',
			'wp_travel_engine_enquiry_submit_name',
			'_wp_http_referer',
			'action',
		);

		// Add Package Name.
		$formdata['package_name'] = $url;

		$valid_form_fields = array_keys( self::get_enquiry_form_fields( $formdata['package_id'] ) );

		// Message.
		foreach ( $formdata as $key => $data ) {
			if ( in_array( $key, $remove_keys ) || ! in_array( $key, $valid_form_fields ) ) {
				unset( $formdata[ $key ] );
				continue;
			}
		}
		// Add customer IP Address.
		$formdata['IP Address:'] = getenv( 'HTTP_CLIENT_IP' ) ?: getenv( 'HTTP_X_FORWARDED_FOR' ) ?: getenv( 'HTTP_X_FORWARDED' ) ?: getenv( 'HTTP_FORWARDED_FOR' ) ?: getenv( 'HTTP_FORWARDED' ) ?: getenv( 'REMOTE_ADDR' ) ?: 'UNKNOWN';

		// Mail class.
		require_once plugin_dir_path( WP_TRAVEL_ENGINE_FILE_PATH ) . 'includes/class-wp-travel-engine-emails.php';

		// Prepare enquiry emails.
		$mail_obj                     = new \WP_Travel_Engine_Emails();
		$admin_email_template_content = $mail_obj->get_email_template( 'enquiry', 'admin', true, $formdata );

		// Prepare admin email template.
		$admin_email_template  = $mail_obj->get_email_header();
		$admin_email_template .= $admin_email_template_content;
		$admin_email_template .= $mail_obj->get_email_footer();

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$uploadedfile = $this->request->get_param( '__files' );
		$attachments  = array();
		foreach ( $uploadedfile as $key => $file ) {
			$upload_file = wp_handle_upload( $file, array( 'test_form' => false ) );
			if ( $upload_file && ! isset( $upload_file['error'] ) ) {
				$attachments[ $key ] = $upload_file['file'];
			}
		}

		if ( ! empty( $wp_travel_engine_settings['email']['enquiry_emailaddress'] ) ) {
			$admin_sent = wp_mail( $to, esc_html( $subject ), $admin_email_template, $headers, $attachments );
		} elseif ( strpos( $wp_travel_engine_settings['email']['emails'], ',' ) !== false ) {
			$wp_travel_engine_settings['email']['emails'] = str_replace( ' ', '', $wp_travel_engine_settings['email']['emails'] );
			$admin_emails                                 = explode( ',', $wp_travel_engine_settings['email']['emails'] );
			$to = array_map( 'sanitize_email', $admin_emails );

			$admin_sent = wp_mail( $to, esc_html( $subject ), $admin_email_template, $headers, $attachments );
		} else {
			$wp_travel_engine_settings['email']['emails'] = str_replace( ' ', '', $wp_travel_engine_settings['email']['emails'] );
			$admin_sent                                   = wp_mail( $to, esc_html( $subject ), $admin_email_template, $headers, $attachments );
		}

		if ( 1 === (int) ( $wp_travel_engine_settings['email']['cust_notif'] ?? null ) ) {
			$headers  = 'MIME-Version: 1.0' . "\r\n";
			$headers .= 'Content-type: text/html; charset=UTF-8' . "\r\n";
			$headers .= 'Reply-To: ' . $to . "\r\n";

			// Create email headers.
			$headers .= 'X-Mailer: PHP/' . phpversion();
			$subject  = apply_filters( 'customer_enquiry_subject', __( 'Enquiry Sent.', 'wp-travel-engine' ) );

			wp_mail( $email, $subject, $admin_email_template, $headers );
		}

		if ( $admin_sent ) {
			$new_post = array(
				'post_title'  => 'enquiry ',
				'post_status' => 'publish',
				'post_type'   => 'enquiry',
			);

			// Insert the post into the database.
			$post_id = wp_insert_post( $new_post );

			if ( ! $post_id ) {
				return false;
			}

			if ( ! is_wp_error( $post_id ) ) :

				/**
				 * @action_hook wte_after_enquiry_created
				 *
				 * @since 2.2.0
				 */
				do_action( 'wte_after_enquiry_created', $post_id );

			endif;

			$arr['enquiry'] = array(
				'name'    => $name,
				'email'   => $email,
				'message' => $enquiry_message,
				'pname'   => $postid->ID,
			);

			add_post_meta( $post_id, 'wp_travel_engine_setting', $arr );

			// Added new meta key for form editor addon support.
			$_formdata = array();
			foreach ( $formdata as $field => $value ) {
				$enquiry_form_data = array();
				if ( is_array( $value ) ) {
					foreach ( $value as $val ) {
						$enquiry_form_data[ $field ] .= $val . ',';
						foreach ( $enquiry_form_data as $form_value ) {
							$_formdata[ $field ] = wp_kses( $form_value, array() );
						}
					}
				} else {
					$_formdata[ $field ] = wp_kses( $value, array() );
				}
			}
			add_post_meta( $post_id, 'wp_travel_engine_enquiry_formdata', $_formdata );

			$title = $name;

			$post_data = array(
				'ID'         => $post_id,
				'post_title' => $title,
			);

			// Update the post into the database.
			wp_update_post( $post_data );

			$result['type']        = 'success';
			$result['message']     = __( 'Your query has been successfully sent. Thank You.', 'wp-travel-engine' );
			$enquiry_thank_you_url = home_url();
			if ( ! empty( $wp_travel_engine_settings['pages']['enquiry'] ) ) {
				$enquiry_thank_you_url = $wp_travel_engine_settings['pages']['enquiry'];
				$enquiry_thank_you_url = get_permalink( $enquiry_thank_you_url );
			}

			$result['redirect'] = $enquiry_thank_you_url;

			/**
			 * Hook - after_enquiry_sent
			 */
			do_action( 'wp_travel_engine_after_enquiry_sent', $post_id );

			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				wp_send_json_success( $result );
				die;
			}
		} else {
			$result['type']    = 'failed';
			$result['message'] = __( 'Sorry, your query could not be sent at the moment. May be try again later. Thank You.', 'wp-travel-engine' );

			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				$result = wp_send_json_error( $result );
				die;
			}
		}
	}

	/**
	 * Gets Enquiry Form Fields.
	 */
	public function get_enquiry_form_fields( $post_id, $args = array() ) {
		$enquiry_form_fields = \WTE_Default_Form_Fields::enquiry();

		if ( ( isset( $args['use_current'] ) && 'yes' === $args['use_current'] && WP_TRAVEL_ENGINE_POST_TYPE === get_post_type( $post_id ) ) || ( ! isset( $args['shortcode'] ) || ! $args['shortcode'] ) ) {
			$package_fields = self::get_package_detail_fields( $post_id );
		} else {
			$trip_select_options = wp_list_pluck(
				get_posts(
					array(
						'post_type'      => WP_TRAVEL_ENGINE_POST_TYPE,
						'post_status'    => 'publish',
						'posts_per_page' => -1,
					)
				),
				'post_title',
				'ID'
			);

			$trip_select_options = array( '' => __( 'Select Trip*', 'wp-travel-engine' ) ) + $trip_select_options;

			$attributes = array();
			if ( ! empty( $args['trip_id'] ) ) {
				$attributes['disabled'] = true;
			}
			$package_fields = array(
				'package_id' => array(
					'field_label'   => __( 'Trip Name', 'wp-travel-engine' ),
					'wrapper_class' => 'row-repeater',
					'type'          => 'select',
					'name'          => 'package_id',
					'id'            => 'package_id',
					'options'       => $trip_select_options,
					'priority'      => 7,
					'default'       => ! empty( $args['trip_id'] ) ? $args['trip_id'] : '',
					'validations'   => array(
						'required' => true,
					),
					'attributes'    => $attributes,
				),
			);
		}

		return apply_filters( 'wp_travel_engine_enquiry_fields_display', array_merge( $package_fields, $enquiry_form_fields ), $post_id );
	}

	/**
	 * Gets Package Details Fields for Enquiry Form.
	 *
	 * @since 5.3.1
	 */
	public function get_package_detail_fields( $post_id ) {
		$wp_travel_engine_settings = get_option( 'wp_travel_engine_settings', array() );
		$enquiry_thank_you_url     = home_url();
		if ( ! empty( $wp_travel_engine_settings['pages']['enquiry'] ) ) {
			$enquiry_thank_you_url = $wp_travel_engine_settings['pages']['enquiry'];
			$enquiry_thank_you_url = get_permalink( $enquiry_thank_you_url );
		}

		$package_detail_fields = array(
			'package_name'  => array(
				'label'    => __( 'Trip Name', 'wp-travel-engine' ),
				'type'     => 'hidden',
				'name'     => 'package_name',
				'id'       => 'package_name',
				'default'  => get_the_title( $post_id ),
				'priority' => 7,
			),
			'package_id'    => array(
				'type'          => 'hidden',
				'name'          => 'package_id',
				'wrapper_class' => 'row-repeater package-name-holder',
				'id'            => 'package_id',
				'default'       => esc_attr( $post_id ),
				'priority'      => 8,
			),
			'package_label' => array(
				'type'          => 'text_info',
				'wrapper_class' => 'row-repeater package-name-holder',
				'field_label'   => __( 'Trip name:', 'wp-travel-engine' ),
				'name'          => 'package_label',
				'id'            => 'package_label',
				'validations'   => array(
					'required' => true,
				),
				'remove_wrap'   => true,
				'default'       => get_the_title( $post_id ),
				'priority'      => 9,
			),
			'redirect_url'  => array(
				'type'          => 'hidden',
				'name'          => 'redirect-url',
				'wrapper_class' => 'row-repeater package-name-holder',
				'id'            => 'redirect-url',
				'default'       => esc_url( $enquiry_thank_you_url ),
				'priority'      => 8,
			),
		);

		return apply_filters( 'wte_enquiry_package_detail_fields', $package_detail_fields, $post_id );
	}
}
