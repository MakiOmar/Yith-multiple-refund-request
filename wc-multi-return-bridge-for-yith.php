<?php
/**
 * Plugin Name: WC Multi Return Bridge for YITH
 * Description: Allows customers to request multiple product returns per order integrated with YITH Advanced Refund System.
 * Version: 1.0.0
 * Author: Makiomar
 * Requires Plugins: WooCommerce, YITH Advanced Refund System
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Multi_Return_Bridge {
    public function __construct() {
        add_action('woocommerce_view_order', array($this, 'render_return_interface'), 25);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_wc_multi_return_submit', array($this, 'ajax_handle_return'));
        add_action('wp_ajax_nopriv_wc_multi_return_submit', array($this, 'ajax_handle_unauthorized'));
    }

    public function enqueue_scripts() {
        if (is_account_page()) {
            wp_enqueue_script(
                'wc-multi-return', 
                plugin_dir_url(__FILE__) . 'assets/js/wc-multi-return.js', 
                array('jquery'), 
                filemtime(plugin_dir_path(__FILE__) . 'assets/js/wc-multi-return.js'), 
                true
            );
            
            wp_localize_script(
                'wc-multi-return',
                'WCMultiReturn',
                array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wc_multi_return_action'),
                    'yith_nonce' => wp_create_nonce('ywcars-submit-request'), // Add this
                    'i18n' => array(
                        'select_one' => __('Please select at least one product to return.', 'wc-multi-return'),
                        'invalid_qty' => __('Please enter a valid quantity for selected item.', 'wc-multi-return'),
                        'success' => __('Return requests submitted successfully.', 'wc-multi-return'),
                        'partial_success' => __('Some requests failed. Please try again.', 'wc-multi-return'),
                        'error' => __('An error occurred. Please try again.', 'wc-multi-return')
                    )
                )
            );
        }
    }

    public function render_return_interface($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order || $order->get_status() !== 'completed') {
            return;
        }

        // Check if YITH refund system is active
        if (!class_exists('YITH_Advanced_Refund_System_Request_Manager')) {
            echo '<div class="woocommerce-error">' . 
                 __('Return system is currently unavailable.', 'wc-multi-return') . 
                 '</div>';
            return;
        }

        $items = $order->get_items();
        $has_refundable_items = false;

        ob_start();
        ?>
        <div class="woocommerce wc-multi-return">
            <h3><?php esc_html_e('Request Product Returns', 'wc-multi-return'); ?></h3>
            <form id="wc-multi-return-form">
                <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>">
                <table class="shop_table shop_table_responsive">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Product', 'wc-multi-return'); ?></th>
                            <th><?php esc_html_e('Qty', 'wc-multi-return'); ?></th>
                            <th><?php esc_html_e('Return Qty', 'wc-multi-return'); ?></th>
                            <th><?php esc_html_e('Reason', 'wc-multi-return'); ?></th>
                            <th><?php esc_html_e('Select', 'wc-multi-return'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item_id => $item) : 
                            $product = $item->get_product();
                            if (!$product || $item->get_quantity() < 1) continue;
                            $has_refundable_items = true;
                        ?>
                            <tr>
                                <td><?php echo esc_html($product->get_name()); ?></td>
                                <td><?php echo esc_html($item->get_quantity()); ?></td>
                                <td>
                                    <input type="number" 
                                           name="return_qty[<?php echo esc_attr($item_id); ?>]" 
                                           min="1" 
                                           max="<?php echo esc_attr($item->get_quantity()); ?>" 
                                           value="1" />
                                </td>
                                <td>
                                    <input type="text" 
                                           name="return_reason[<?php echo esc_attr($item_id); ?>]" 
                                           value="" 
                                           placeholder="<?php esc_attr_e('Reason for return', 'wc-multi-return'); ?>" />
                                </td>
                                <td>
                                    <input type="checkbox" 
                                           name="selected_items[]" 
                                           value="<?php echo esc_attr($item_id); ?>" />
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($has_refundable_items) : ?>
                    <button type="submit" class="button return-selected">
                        <?php esc_html_e('Submit Returns', 'wc-multi-return'); ?>
                    </button>
                <?php else : ?>
                    <p><?php esc_html_e('No refundable items in this order.', 'wc-multi-return'); ?></p>
                <?php endif; ?>
            </form>
        </div>
        <?php
        echo ob_get_clean();
    }

    public function ajax_handle_return() {
        
        try {
            check_ajax_referer('wc_multi_return_action', '_ajax_nonce');
            
            if (!is_user_logged_in()) {
                throw new Exception(__('Authentication required.', 'wc-multi-return'));
            }

            $order_id = absint($_POST['order_id'] ?? 0);
            $items = isset($_POST['items']) ? json_decode(stripslashes($_POST['items']), true) : array();
            
            if (!$order_id || empty($items)) {
                throw new Exception(__('Missing order or items.', 'wc-multi-return'));
            }

            $order = wc_get_order($order_id);
            if (!$order || $order->get_customer_id() !== get_current_user_id()) {
                throw new Exception(__('Invalid order.', 'wc-multi-return'));
            }

            $results = array();
            foreach ($items as $item) {
                $item_id = absint($item['item_id'] ?? 0);
                $results[$item_id] = $this->process_single_return($order, $item);
            }

            wp_send_json_success(array(
                'message' => __('Return requests processed.', 'wc-multi-return'),
                'results' => $results
            ));

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    protected function process_single_return($order, $item_data) {
        $item_id = absint($item_data['item_id'] ?? 0);
        $qty = absint($item_data['qty'] ?? 0);
        $reason = sanitize_text_field($item_data['reason'] ?? '');
        
        $order_item = $order->get_item($item_id);
        if (!$order_item || $qty < 1) {
            return false;
        }

        // Verify quantity doesn't exceed available
        $max_qty = $order_item->get_quantity();
        $qty = min($qty, $max_qty);
        
        // Prepare YITH request data
        $request_data = array(
            'action' => 'ywcars_submit_request',
            'ywcars_form_order_id' => $order->get_id(),
            'ywcars_form_product_id' => $order_item->get_product_id(),
            'ywcars_form_item_id' => $item_id,
            'ywcars_form_qty' => $qty,
            'ywcars_form_qty_total' => $max_qty,
            'ywcars_form_max_qty' => $max_qty,
            'ywcars_form_line_total' => $order_item->get_total(),
            'ywcars_form_reason' => $reason ?: __('No reason provided', 'wc-multi-return'),
            'security' => sanitize_text_field($item_data['yith_nonce'] ?? ''),
            'ywcars_form_whole_order' => false
        );
        
        try {
            $original_post = $_POST;
            $_POST = $request_data;
            
            $result = $this->submit_request();
            
            $_POST = $original_post;
            return $result !== false;
        } catch (Exception $e) {
            error_log('YITH Refund Error: ' . $e->getMessage());
            return false;
        }
    }
    /** Submit request from POST
	 *
	 * @throws Exception Cannot submit messages (demo mode or missing data).
	 * @throws YITH_Upload_Exception Fail on upload.
	 */
	public function submit_request() {
		// Create the request object with all $_POST data and save.
		$order_id    = ! empty( $_POST['ywcars_form_order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ywcars_form_order_id'] ) ) : false;
		$whole_order = ! empty( $_POST['ywcars_form_whole_order'] ) ? $_POST['ywcars_form_whole_order'] : false; // phpcs:ignore
		$product_id  = ! empty( $_POST['ywcars_form_product_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ywcars_form_product_id'] ) ) : false;
		$item_id     = ! empty( $_POST['ywcars_form_item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ywcars_form_item_id'] ) ) : false;
		$qty         = ! empty( $_POST['ywcars_form_qty'] ) ? sanitize_text_field( wp_unslash( $_POST['ywcars_form_qty'] ) ) : false;
		$max_qty     = ! empty( $_POST['ywcars_form_max_qty'] ) ? sanitize_text_field( wp_unslash( $_POST['ywcars_form_max_qty'] ) ) : false;
		$qty_total   = ! empty( $_POST['ywcars_form_qty_total'] ) ? sanitize_text_field( wp_unslash( $_POST['ywcars_form_qty_total'] ) ) : false;
		$line_total  = ! empty( $_POST['ywcars_form_line_total'] ) ? sanitize_text_field( wp_unslash( $_POST['ywcars_form_line_total'] ) ) : false;
        
		try {
			$demo = apply_filters( 'ywcars_demo_mode', false );
			if ( $demo ) {
				throw new Exception(
					esc_html__(
						'You cannot submit new requests on this Live Demo. Click on Launch Admin Demo to unblock this and fully test the plugin.',
						'yith-advanced-refund-system-for-woocommerce'
					)
				);
			}
			if ( $whole_order ) {
				if ( ! $order_id || ! $line_total ) {
					throw new Exception(
						esc_html_x(
							'Exception on submitting request: missing data.',
							'Exception description. Technical error message.',
							'yith-advanced-refund-system-for-woocommerce'
						)
					);
				}
			} else {
				if ( ! $order_id || ! isset( $product_id ) || ! $item_id || ! $qty || ! $qty_total || ! isset( $line_total ) ) {
					throw new Exception(
						esc_html_x(
							'Exception on submitting request: missing data.',
							'Exception description. Technical error message.',
							'yith-advanced-refund-system-for-woocommerce'
						)
					);
				}
				if ( $qty > $max_qty || $qty > $qty_total ) {
					throw new Exception(
						esc_html_x(
							'Exception on submitting request: quantity limit exceeded.',
							'Exception description. Technical error message.',
							'yith-advanced-refund-system-for-woocommerce'
						)
					);
				}
			}

			$request = new YITH_Refund_Request();

			$request->order_id        = $order_id;
			$request->whole_order     = $whole_order;
			$request->refund_id       = 0;
			$request->refunded_amount = 0;
			$request->coupon_id       = 0;

			$order = wc_get_order( $request->order_id );

			// Save order item meta for each item.
			if ( $request->whole_order ) {
				$request->refund_total = $line_total;
				$items                 = $order->get_items();
				foreach ( $items as $item_id => $item ) {
					wc_update_order_item_meta( $item_id, '_ywcars_item_requested', 'yes' );
					wc_update_order_item_meta( $item_id, '_ywcars_requested_qty', $item['qty'] );
				}
			} else {
				$request->product_id = $product_id;
				$request->item_id    = $item_id;
				$request->item_total = $line_total;
				$request->qty        = $qty;
				$request->qty_total  = $qty_total;
				$request->item_value = $request->item_total / $request->qty_total;

				$line_tax                  = wc_get_order_item_meta( $item_id, '_line_tax' );
				$request->tax_value        = empty( $line_tax ) ? 0 : $line_tax / $request->qty_total;
				$request->tax_total        = $request->tax_value * $request->qty_total;
				$request->tax_refund_total = $request->tax_value * $request->qty;
				$line_tax_data             = maybe_unserialize( wc_get_order_item_meta( $item_id, '_line_tax_data' ) );
				if ( $line_tax_data ) {
					foreach ( $line_tax_data['total'] as &$tax ) {
						$single_tax_value = $tax / $request->qty_total;
						$tax              = $single_tax_value * $request->qty;
					}
				}

				$request->item_tax_data     = $line_tax_data ? $line_tax_data['total'] : ''; // Save only 'total' array.
				$request->item_refund_total = $request->item_value * $request->qty;
				$request->refund_total      = $request->item_refund_total + $request->tax_refund_total;

				wc_update_order_item_meta( $request->item_id, '_ywcars_item_requested', 'yes' );
				wc_update_order_item_meta( $request->item_id, '_ywcars_requested_qty', $request->qty );
			}

			$request->save();

			// Save the request message with their meta data.
			if ( $request->exists() && isset( $_POST['ywcars_form_reason'] ) ) {
				$message = new YITH_Request_Message();

				$this->new_message( $message, $request->ID, sanitize_text_field( wp_unslash( $_POST['ywcars_form_reason'] ) ) );
			}

			if ( 'yes' === get_option( 'yith_wcars_automatic_refunds' ) && apply_filters( 'yith_ywcars_automatic_refunds_for_virtual_products', ! self::request_has_physical_products( $request ), $request ) ) {
				wp_schedule_single_event( strtotime( '+1 minutes' ), 'ywcars_process_automatic_request', array( $request->ID ) );
			}

			WC()->mailer();
			do_action( 'ywcars_send_new_request_user', $request->ID );
			do_action( 'ywcars_send_new_request_admin', $request->ID );
			/* translators: %s: Request ID */
			wc_add_notice( sprintf( apply_filters( 'ywcars_request_submitted_text', esc_html__( 'Request #%s submitted successfully', 'yith-advanced-refund-system-for-woocommerce' ), $request->ID ), $request->ID ) );
			return true;
		} catch ( YITH_Upload_Exception $e ) {
			error_log( $e->getMessage() );
			return false;
		} catch ( Exception $e ) {
			error_log( $e->getMessage() );
			return false;
		}

	}

			/**
		 * Create a new message in request.
		 *
		 * @param  mixed $message         Message (object).
		 * @param  int   $request_id      Current request ID.
		 * @param  mixed $message_content Content of message.
		 * @throws YITH_Upload_Exception When opening the file.
		 */
		public function new_message( $message, $request_id, $message_content ) {
			if ( ! ( $message instanceof YITH_Request_Message ) ) {
				return;
			}

			$message->request = $request_id;
			$message->message = $message_content;
			$message->author  = get_current_user_id();

			$message->save();

			if ( isset( $_FILES['ywcars_form_attachment'] ) && ! empty( $_FILES['ywcars_form_attachment'] ) ) {
				try {
					if ( isset( $_FILES['ywcars_form_attachment']['error'] ) ) {
						foreach ( $_FILES['ywcars_form_attachment']['error'] as $key => $error ) {
							if ( isset( $_FILES['ywcars_form_attachment']['tmp_name'][ $key ] ) && isset( $_FILES['ywcars_form_attachment']['name'][ $key ] ) && ( UPLOAD_ERR_OK === $error || UPLOAD_ERR_NO_FILE === $error ) ) {
								$tmp_name  = $_FILES['ywcars_form_attachment']['tmp_name'][ $key ]; //phpcs:ignore
								$name      = $_FILES['ywcars_form_attachment']['name'][ $key ]; //phpcs:ignore
								$extension = pathinfo( $name, PATHINFO_EXTENSION );
								$id_name   = uniqid( 'ywcars_' ) . '.' . $extension;
								$result    = move_uploaded_file( $tmp_name, YITH_WCARS_UPLOADS_DIR . $id_name );
								if ( $result ) {
									$message->add_message_meta( $name, YITH_WCARS_UPLOADS_URL . $id_name );
								}
							} else {
								throw new YITH_Upload_Exception( (int) $error );
							}
						}
					}
				} catch ( YITH_Upload_Exception $e ) {
					error_log( $e->getMessage() );
					return false;
				}
			}
		}
    public function ajax_handle_unauthorized() {
        wp_send_json_error(__('You must be logged in to submit return requests.', 'wc-multi-return'));
    }
}

new WC_Multi_Return_Bridge();