<?php
/**
 * Main
 *
 * @package Yith
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber,WordPress.Security.NonceVerification.Missing,Squiz.Commenting.FunctionCommentThrowTag.Missing
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.PHP.DevelopmentFunctions.error_log_error_log, Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
/**
 * Class WC_Multi_Return_Bridge
 */
class WC_Multi_Return_Bridge {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_view_order', array( $this, 'render_return_interface' ), 25 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_wc_multi_return_submit', array( $this, 'ajax_handle_return' ) );
		add_action( 'wp_ajax_nopriv_wc_multi_return_submit', array( $this, 'ajax_handle_unauthorized' ) );
	}
	/**
	 * Enqueue scripts and styles.
	 */
	public function enqueue_scripts() {
		if ( is_account_page() ) {
			wp_enqueue_style(
				'wc-multi-return-css',
				plugin_dir_url( __FILE__ ) . 'assets/css/wc-multi-return.css',
				array(),
				filemtime( plugin_dir_path( __FILE__ ) . 'assets/css/wc-multi-return.css' )
			);

			wp_enqueue_script(
				'wc-multi-return',
				plugin_dir_url( __FILE__ ) . 'assets/js/wc-multi-return.js',
				array( 'jquery' ),
				filemtime( plugin_dir_path( __FILE__ ) . 'assets/js/wc-multi-return.js' ),
				true
			);

			wp_localize_script(
				'wc-multi-return',
				'WCMultiReturn',
				array(
					'ajax_url'   => admin_url( 'admin-ajax.php' ),
					'nonce'      => wp_create_nonce( 'wc_multi_return_action' ),
					'yith_nonce' => wp_create_nonce( 'ywcars-submit-request' ),
					'i18n'       => array(
						'select_one'      => __( 'Please select at least one product to return.', 'wc-multi-return' ),
						'invalid_qty'     => __( 'Please enter a valid quantity for selected item.', 'wc-multi-return' ),
						'success'         => __( 'Return requests submitted successfully.', 'wc-multi-return' ),
						'partial_success' => __( 'Some requests failed. Please try again.', 'wc-multi-return' ),
						'error'           => __( 'An error occurred. Please try again.', 'wc-multi-return' ),
					),
				)
			);
		}
	}
	/**
	 * Render the return interface on the order view page.
	 *
	 * @param int $order_id Order ID.
	 */
	public function render_return_interface( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || ! in_array( $order->get_status(), array( 'completed', 'processing' ), true ) ) {
			return;
		}

		// Check if YITH refund system is active.
		if ( ! class_exists( 'YITH_Advanced_Refund_System_Request_Manager' ) ) {
			echo '<div class="woocommerce-error">' .
				esc_html__( 'Return system is currently unavailable.', 'wc-multi-return' ) .
				'</div>';
			return;
		}

		$items                = $order->get_items();
		$has_refundable_items = false;

		// Get existing requests for this order.
		$existing_requests = $this->get_existing_requests( $order_id );

		ob_start();
		?>
		<div class="woocommerce wc-multi-return-container">
			<h2><?php esc_html_e( 'Request Product Returns', 'wc-multi-return' ); ?></h2>
			
			<?php if ( ! empty( $existing_requests ) ) : ?>
			<div class="wc-multi-return-existing-requests">
				<h3><?php esc_html_e( 'Your Existing Return Requests', 'wc-multi-return' ); ?></h3>
				<div class="table-responsive">
					<table class="wc-multi-return-requests-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Product', 'wc-multi-return' ); ?></th>
								<th><?php esc_html_e( 'Requested Qty', 'wc-multi-return' ); ?></th>
								<th><?php esc_html_e( 'Reason', 'wc-multi-return' ); ?></th>
								<th><?php esc_html_e( 'Status', 'wc-multi-return' ); ?></th>
								<th><?php esc_html_e( 'Date', 'wc-multi-return' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $existing_requests as $request ) : ?>
								<tr>
									<td><?php echo esc_html( $request['product_name'] ); ?></td>
									<td><?php echo esc_html( $request['qty'] ); ?></td>
									<td><?php echo esc_html( $request['reason'] ); ?></td>
									<td><span class="request-status <?php echo esc_attr( strtolower( $request['status'] ) ); ?>"><?php echo esc_html( $request['status'] ); ?></span></td>
									<td><?php echo esc_html( $request['date'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php endif; ?>
			
			<div class="wc-multi-return-new-request">
				<h3><?php esc_html_e( 'New Return Request', 'wc-multi-return' ); ?></h3>
				<form id="wc-multi-return-form">
					<input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">
					<div class="table-responsive">
						<table class="wc-multi-return-products-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Product', 'wc-multi-return' ); ?></th>
									<th><?php esc_html_e( 'Qty', 'wc-multi-return' ); ?></th>
									<th><?php esc_html_e( 'Return Qty', 'wc-multi-return' ); ?></th>
									<th><?php esc_html_e( 'Reason', 'wc-multi-return' ); ?></th>
									<th><?php esc_html_e( 'Select', 'wc-multi-return' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $items as $item_id => $item ) :
									$product = $item->get_product();
									if ( ! $product || $item->get_quantity() < 1 ) {
										continue;
									}

									// Check if item already has a return request.
									$is_requested  = wc_get_order_item_meta( $item_id, '_ywcars_item_requested', true ) === 'yes';
									$requested_qty = wc_get_order_item_meta( $item_id, '_ywcars_requested_qty', true );
									$max_qty       = $item->get_quantity() - ( $is_requested ? $requested_qty : 0 );

									if ( $max_qty > 0 ) {
										$has_refundable_items = true;
									}
									?>
									<tr class="<?php echo $is_requested ? 'item-requested' : ''; ?>">
										<td>
											<?php echo esc_html( $product->get_name() ); ?>
											<?php if ( $is_requested ) : ?>
												<span class="already-requested-badge"><?php esc_html_e( 'Already requested', 'wc-multi-return' ); ?></span>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( $item->get_quantity() ); ?></td>
										<td>
											<?php if ( $max_qty > 0 ) : ?>
												<input type="number" 
														name="return_qty[<?php echo esc_attr( $item_id ); ?>]" 
														min="1" 
														max="<?php echo esc_attr( $max_qty ); ?>" 
														value="1" 
														class="return-qty-input" />
											<?php else : ?>
												<span class="no-qty-available"><?php esc_html_e( 'N/A', 'wc-multi-return' ); ?></span>
											<?php endif; ?>
										</td>
										<td>
											<?php if ( $max_qty > 0 ) : ?>
												<input type="text" 
														name="return_reason[<?php echo esc_attr( $item_id ); ?>]" 
														value="" 
														placeholder="<?php esc_attr_e( 'Reason for return', 'wc-multi-return' ); ?>" 
														class="return-reason-input" />
											<?php else : ?>
												<span class="no-reason-available"><?php esc_html_e( 'N/A', 'wc-multi-return' ); ?></span>
											<?php endif; ?>
										</td>
										<td>
											<?php if ( $max_qty > 0 ) : ?>
												<input type="checkbox" 
														name="selected_items[]" 
														value="<?php echo esc_attr( $item_id ); ?>" 
														class="return-item-checkbox" />
											<?php else : ?>
												<span class="checkbox-disabled">—</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					
					<?php if ( $has_refundable_items ) : ?>
						<div class="return-submit-wrapper">
							<button type="submit" class="button alt return-selected">
								<?php esc_html_e( 'Submit Returns', 'wc-multi-return' ); ?>
							</button>
						</div>
					<?php else : ?>
						<p class="no-refundable-items"><?php esc_html_e( 'No refundable items available in this order.', 'wc-multi-return' ); ?></p>
					<?php endif; ?>
				</form>
			</div>
		</div>
		<?php
		//phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo ob_get_clean();
		//phpcs:enable
	}
	/**
	 * Get existing return requests for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return array List of return requests.
	 */
	protected function get_existing_requests( $order_id ) {
		if ( ! class_exists( 'YITH_Advanced_Refund_System_Request_Manager' ) ) {
			return array();
		}

		$requests = array();
		$args     = array(
			'meta_key'       => '_order_id',
			'meta_value'     => $order_id,
			'post_type'      => 'ywcars_request',
			'posts_per_page' => -1,
			'post_status'    => 'any',
		);

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$request_id = get_the_ID();

				// Get request details.
				$request = new YITH_Refund_Request( $request_id );

				if ( $request->whole_order ) {
					// Handle whole order requests.
					$order = wc_get_order( $order_id );
					foreach ( $order->get_items() as $item_id => $item ) {
						$product    = $item->get_product();
						$requests[] = array(
							'product_name' => $product ? $product->get_name() : esc_html__( 'Deleted product', 'wc-multi-return' ),
							'qty'          => $item->get_quantity(),
							'reason'       => $this->get_request_reason( $request_id ),
							'status'       => $request->get_status_label(),
							'date'         => get_the_date( '', $request_id ),
						);
					}
				} else {
					// Handle single item requests.
					$item_id = $request->item_id;
					$item    = $order->get_item( $item_id );
					$product = $item ? $item->get_product() : null;

					$requests[] = array(
						'product_name' => $product ? $product->get_name() : esc_html__( 'Deleted product', 'wc-multi-return' ),
						'qty'          => $request->qty,
						'reason'       => $this->get_request_reason( $request_id ),
						'status'       => $request->get_status_label(),
						'date'         => get_the_date( '', $request_id ),
					);
				}
			}
		}

		wp_reset_postdata();
		return $requests;
	}
	/**
	 * Get the reason for a request.
	 *
	 * @param int $request_id Request ID.
	 * @return string Reason text.
	 */
	protected function get_request_reason( $request_id ) {
		$messages = get_posts(
			array(
				'post_type'      => 'ywcars_message',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_key'       => '_request',
				'meta_value'     => $request_id,
			)
		);

		if ( ! empty( $messages ) ) {
			return $messages[0]->post_content;
		}

		return esc_html__( 'No reason provided', 'wc-multi-return' );
	}
	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing
	/**
	 * Handle AJAX return request.
	 */
	public function ajax_handle_return() {

		try {
			check_ajax_referer( 'wc_multi_return_action', '_ajax_nonce' );

			if ( ! is_user_logged_in() ) {
				throw new Exception( esc_html__( 'Authentication required.', 'wc-multi-return' ) );
			}

			$order_id = absint( $_POST['order_id'] ?? 0 );
			$items    = isset( $_POST['items'] ) ? json_decode( stripslashes( sanitize_text_field( wp_unslash( $_POST['items'] ) ) ), true ) : array();

			if ( ! $order_id || empty( $items ) ) {
				throw new Exception( esc_html__( 'Missing order or items.', 'wc-multi-return' ) );
			}

			$order = wc_get_order( $order_id );
			if ( ! $order || $order->get_customer_id() !== get_current_user_id() ) {
				throw new Exception( esc_html__( 'Invalid order.', 'wc-multi-return' ) );
			}

			$results = array();
			foreach ( $items as $item ) {
				$item_id             = absint( $item['item_id'] ?? 0 );
				$results[ $item_id ] = $this->process_single_return( $order, $item );
			}

			wp_send_json_success(
				array(
					'message' => esc_html__( 'Return requests processed.', 'wc-multi-return' ),
					'results' => $results,
				)
			);

		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}
	/**
	 * Process a single item return.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $item_data Item data.
	 * @return bool True if success, false otherwise.
	 */
	protected function process_single_return( $order, $item_data ) {
		$item_id = absint( $item_data['item_id'] ?? 0 );
		$qty     = absint( $item_data['qty'] ?? 0 );
		$reason  = sanitize_text_field( $item_data['reason'] ?? '' );

		$order_item = $order->get_item( $item_id );
		if ( ! $order_item || $qty < 1 ) {
			return false;
		}

		// Verify quantity doesn't exceed available.
		$max_qty = $order_item->get_quantity();
		$qty     = min( $qty, $max_qty );

		// Prepare YITH request data.
		$request_data = array(
			'action'                  => 'ywcars_submit_request',
			'ywcars_form_order_id'    => $order->get_id(),
			'ywcars_form_product_id'  => $order_item->get_product()->get_id(),
			'ywcars_form_item_id'     => $item_id,
			'ywcars_form_qty'         => $qty,
			'ywcars_form_qty_total'   => $max_qty,
			'ywcars_form_max_qty'     => $max_qty,
			'ywcars_form_line_total'  => $order_item->get_total(),
			'ywcars_form_reason'      => $reason ? esc_html__( 'No reason provided', 'wc-multi-return' ) : '',
			'security'                => sanitize_text_field( $item_data['yith_nonce'] ?? '' ),
			'ywcars_form_whole_order' => false,
		);

		try {
			$original_post = $_POST;
			$_POST         = $request_data;

			$result = $this->submit_request();

			$_POST = $original_post;
			return false !== $result;
		} catch ( Exception $e ) {
			error_log( 'YITH Refund Error: ' . $e->getMessage() );
			return false;
		}
	}
	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber,WordPress.Security.NonceVerification.Missing
	/** Submit request from POST
	 *
	 * @throws Exception Cannot submit messages (demo mode or missing data).
	 * @throws YITH_Upload_Exception Fail on upload.
	 */
	public function submit_request() {
		$_req = $_POST;
		// Create the request object with all $_req data and save.
		$order_id    = ! empty( $_req['ywcars_form_order_id'] ) ? sanitize_text_field( wp_unslash( $_req['ywcars_form_order_id'] ) ) : false;
		$whole_order = ! empty( $_req['ywcars_form_whole_order'] ) ? $_req['ywcars_form_whole_order'] : false; // phpcs:ignore.
		$product_id  = ! empty( $_req['ywcars_form_product_id'] ) ? sanitize_text_field( wp_unslash( $_req['ywcars_form_product_id'] ) ) : false;
		$item_id     = ! empty( $_req['ywcars_form_item_id'] ) ? sanitize_text_field( wp_unslash( $_req['ywcars_form_item_id'] ) ) : false;
		$qty         = ! empty( $_req['ywcars_form_qty'] ) ? sanitize_text_field( wp_unslash( $_req['ywcars_form_qty'] ) ) : false;
		$max_qty     = ! empty( $_req['ywcars_form_max_qty'] ) ? sanitize_text_field( wp_unslash( $_req['ywcars_form_max_qty'] ) ) : false;
		$qty_total   = ! empty( $_req['ywcars_form_qty_total'] ) ? sanitize_text_field( wp_unslash( $_req['ywcars_form_qty_total'] ) ) : false;
		$line_total  = ! empty( $_req['ywcars_form_line_total'] ) ? sanitize_text_field( wp_unslash( $_req['ywcars_form_line_total'] ) ) : false;

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
			if ( $request->exists() && isset( $_req['ywcars_form_reason'] ) ) {
				$message = new YITH_Request_Message();

				$this->new_message( $message, $request->ID, sanitize_text_field( wp_unslash( $_req['ywcars_form_reason'] ) ) );
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
							$tmp_name  = $_FILES['ywcars_form_attachment']['tmp_name'][ $key ]; //phpcs:ignore.
							$name      = $_FILES['ywcars_form_attachment']['name'][ $key ]; //phpcs:ignore.
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
	/**
	 * Handle unauthorized AJAX request.
	 */
	public function ajax_handle_unauthorized() {
		wp_send_json_error( __( 'You must be logged in to submit return requests.', 'wc-multi-return' ) );
	}
}

new WC_Multi_Return_Bridge();
