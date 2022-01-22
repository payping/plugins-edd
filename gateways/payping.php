<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'EDD_payping_Gateway' ) ) :

	class EDD_payping_Gateway {
        
        /* Show Debug In Console */
        public function WC_GPP_Debug_Log($Mode_Debug='null', $object=null, $label=null )
        {
            if($Mode_Debug === '1'){
                $object = $object; 
                $message = json_encode( $object, JSON_UNESCAPED_UNICODE );
                $label = "Debug".($label ? " ($label): " : ': '); 
				echo sprintf("<script>console.warn(\"%s\", %s);</script>", esc_html($label), esc_html($messages));
                file_put_contents(EDD_GPPDIR.'/log_payping.txt', $label."\n".$message."\n\n", FILE_APPEND);
            }
        }
        
		public function __construct() {

			add_filter( 'edd_payment_gateways', array( $this, 'add' ) );
			add_action( 'edd_payping_cc_form' , array( $this, 'cc_form' ) );
			add_action( 'edd_gateway_payping' , array( $this, 'process' ) );
			add_action( 'edd_verify_payping' , array( $this, 'verify' ) );
			add_filter( 'edd_settings_gateways', array( $this, 'settings' ) );

			add_action( 'edd_payment_receipt_after', array( $this, 'receipt' ) );

			add_action( 'init', array( $this, 'listen' ) );
		}

		public function add( $gateways ) {
			global $edd_options;

			$gateways[ 'payping' ] = array(
				'checkout_label' 		=>	isset( $edd_options['payping_label'] ) ? $edd_options['payping_label'] : 'درگاه پرداخت آنلاین پی‌پینگ',
				'admin_label' 			=>	'پی‌پینگ'
			);

			return $gateways;
		}

		public function cc_form() {
			return;
		}

		public function process( $purchase_data ) {
			$user_data = $purchase_data['user_info'];
	        if(isset($user_data)){
				$first_name = $user_data['first_name'];
				$last_name = $user_data['last_name'];
				$full_name = $user_data['first_name'].' '. $user_data['last_name'];
			}else{
				$first_name = __('وارد نشده‌است', 'payping');
				$last_name = __('وارد نشده‌است', 'payping');
				$full_name = $user_data['first_name'].' '. $user_data['last_name'];
			}
			global $edd_options;
			@ session_start();
			$payment = $this->insert_payment( $purchase_data );

			if ( $payment ) {

				$tokenCode = ( isset( $edd_options[ 'payping_tokenCode' ] ) ? $edd_options[ 'payping_tokenCode' ] : '' );
				$desc = 'پرداخت شماره #' . $payment;
				$callback = add_query_arg( 'verify_payping', '1', get_permalink( $edd_options['success_page'] ) );

				$amount = intval( $purchase_data['price'] ) ;
				if ( edd_get_currency() == 'IRR' or strtoupper(edd_get_currency()) == 'RIAL' or strtoupper(edd_get_currency()) == 'ريال' )
					$amount = $amount / 10; // Return back to original one.

				$data = array(
					'clientRefId' 			=>	$payment,
					'payerName'             =>  $full_name,
					'payerIdentity'         =>  $purchase_data['user_email'],
					'Amount' 				=>	$amount,
					'Description' 			=>	$desc,
					'ReturnUrl' 			=>	$callback
				);
                
                $args = array(
                    'body' => json_encode($data),
                    'timeout' => '45',
                    'redirection' => '5',
                    'httpsversion' => '1.0',
                    'blocking' => true,
	                'headers' => array(
		              'Authorization' => 'Bearer '.$tokenCode,
		              'Content-Type'  => 'application/json',
		              'Accept' => 'application/json'
		              ),
                    'cookies' => array()
                );

                    $response = wp_remote_post('https://api.payping.ir/v2/pay', $args);
                    $res_header = wp_remote_retrieve_headers($response);
                    $payping_id_request = $res_header['x-paypingrequest-id'];
                
                    /* Call Function Show Debug In Console */
                    $this->WC_GPP_Debug_Log($edd_options['payping_header_Debug'], $response, "Pay");
                
                    if ( is_wp_error($response) ) {
                        edd_insert_payment_note( $payment, 'شناسه درخواست پی‌پینگ: '.$payping_id_request );
						edd_update_payment_status( $payment, 'failed' );
						edd_set_error( 'payping_connect_error', 'در اتصال به درگاه مشکلی پیش آمد.' );
						edd_send_back_to_checkout();
						return false;
					}else{
                        $code = wp_remote_retrieve_response_code( $response );
                        if($code === 200){
							if (isset($response["body"]) and $response["body"] != '') {
								$code_pay = wp_remote_retrieve_body($response);
								$code_pay =  json_decode($code_pay, true);
                                edd_insert_payment_note( $payment, 'کد تراکنش پی‌پینگ: '.$code_pay );
								edd_update_payment_meta( $payment, 'payping_code', $code_pay );
								$_SESSION['pp_payment'] = $payment;
								wp_redirect(sprintf('https://api.payping.ir/v2/pay/gotoipg/%s', $code_pay["code"]));
								exit;
							}else{
                                $Message = ' تراکنش ناموفق بود- شرح خطا : عدم وجود کد ارجاع ';
								edd_insert_payment_note( $payment, $Message  );
								edd_update_payment_status( $payment, 'failed' );
								edd_set_error( 'payping_connect_error', $Message );
								edd_send_back_to_checkout();
                            }
                    }else{
							$Message = $this->error_reason(wp_remote_retrieve_response_code( $response ));
							edd_insert_payment_note( $payment, $Message  );
							edd_update_payment_status( $payment, 'failed' );
							edd_set_error( 'payping_connect_error', $Message );
							edd_send_back_to_checkout();
				    }
                    
				}
            }
		}


		public function verify() {
			global $edd_options;

			if ( isset( $_REQUEST['refid'] ) ) {
				$refid = sanitize_text_field( $_REQUEST['refid'] );
				$payment = edd_get_payment( $_SESSION['pp_payment'] );
				unset( $_SESSION['pp_payment'] );
				if ( ! $payment ) {
					$payment = edd_get_payment( sanitize_text_field( $_REQUEST['clientrefid'] ) );
				}
				if ( $payment->status == 'complete' ) return false;

				$amount = intval( edd_get_payment_amount( $payment->ID ) ) ;
				if ( edd_get_currency() == 'IRR' or strtoupper(edd_get_currency()) == 'RIAL' or strtoupper(edd_get_currency()) == 'ريال' )
					$amount = $amount / 10; // Return back to original one.

				$tokenCode = ( isset( $edd_options[ 'payping_tokenCode' ] ) ? $edd_options[ 'payping_tokenCode' ] : '' );

				$data =  array(
					'amount' 		    =>	$amount,
					'refId'				=>	$refid
				) ;
                
                        $args = array(
                            'body' => json_encode($data),
                            'timeout' => '45',
                            'redirection' => '5',
                            'httpsversion' => '1.0',
                            'blocking' => true,
	                        'headers' => array(
	                       	'Authorization' => 'Bearer ' .$tokenCode,
	                       	'Content-Type'  => 'application/json',
	                       	'Accept' => 'application/json'
	                       	),
                         'cookies' => array()
                        );
                    $response = wp_remote_post('https://api.payping.ir/v1/pay/verify', $args);
                    
                    /* Call Function Show Debug In Console */
                    $this->WC_GPP_Debug_Log($edd_options['payping_header_Debug'], $response, "Verify");
                
                    $res_header = wp_remote_retrieve_headers($response);
                    $payping_id_request = $res_header['x-paypingrequest-id'];
                    edd_empty_cart();

					if ( version_compare( EDD_VERSION, '2.1', '>=' ) )
						edd_set_payment_transaction_id( $payment->ID, $refid );							
                
                    if ( is_wp_error($response) ) {
                        $Status = 'failed';
				        $Fault = 'wp-remote Error.';
						$Message = 'خطا در ارتباط به پی‌پینگ : شرح خطا '.$payping_id_request;
					}else{
						$code = wp_remote_retrieve_response_code( $response );
						if ( $code == 200 ) {
							if (isset($refid) and $refid != '') {
								edd_insert_payment_note( $payment->ID, 'شماره تراکنش: '.$refid );
								edd_update_payment_meta( $payment->ID, 'payping_refid', $refid );
								edd_update_payment_status( $payment->ID, 'publish' );
								edd_send_to_success_page();
							}else{
								$Message = 'متافسانه سامانه قادر به دریافت کد پیگیری نمی باشد! نتیجه درخواست : '.$payping_id_request;
								edd_insert_payment_note( $payment->ID, $Message );
								edd_update_payment_meta( $payment->ID, 'payping_refid', $refid );
								edd_update_payment_status( $payment->ID, 'failed' );
								wp_redirect( get_permalink( $edd_options['failure_page'] ) );
							}
						}else{
							
							$Message = wp_remote_retrieve_response_message( $response );
							edd_insert_payment_note( $payment->ID, $Message );
							edd_update_payment_meta( $payment->ID, 'payping_refid', $refid );
							edd_update_payment_status( $payment->ID, 'failed' );
							wp_redirect( get_permalink( $edd_options['failure_page'] ) );
							exit;
						}
					}

			}
		}

		/**
		 * Receipt field for payment
		 *
		 * @param 				object $payment
		 * @return 				void
		 */
		public function receipt( $payment ) {
			$refid = edd_get_payment_meta( $payment->ID, 'payping_refid' );
			if ( $refid ) {
				echo sprintf('<tr class="payping-ref-id-row epp-field ehsaan-me"><td><strong>شماره تراکنش بانکی:</strong></td><td>%s</td></tr>', esc_attr($refid));
			}
		}

		/**
		 * Gateway settings
		 *
		 * @param 				array $settings
		 * @return 				array
		 */
		public function settings( $settings ) {
			return array_merge( $settings, array(
				'payping_header' 		=>	array(
					'id' 			=>	'payping_header',
					'type' 			=>	'header',
					'name' 			=>	'افزونه درگاه پرداخت <strong>پی‌پینگ</strong>'
				),
				'payping_tokenCode' 		=>	array(
					'id' 			=>	'payping_tokenCode',
					'name' 			=>	'کد توکن اختصاصی',
					'type' 			=>	'text',
					'size' 			=>	'regular'
				),
				'payping_label' 	=>	array(
					'id' 			=>	'payping_label',
					'name' 			=>	'نام درگاه در صفحه پرداخت',
					'type' 			=>	'text',
					'size' 			=>	'regular',
					'std' 			=>	'پرداخت از طریق پی‌پینگ'
				),
                'payping_header_Debug' 		=>	array(
					'id' 			=>	'payping_header_Debug',
					'type' 			=>	'checkbox',
                    'name' 			=>	'تنظیمات اشکال زدایی',
					'desc' 			=>	'تنظیمات اشکال زدایی <span style="font-size:12px;color:red;">این بخش برای توسعه دهندگان است.(در صورت نداشتن اطلاعات کافی آن را رها کنید).</span>',
                    'defualt'       => 'yes'
				),
			) );
		}


		/**
		 * Inserts a payment into database
		 *
		 * @param 			array $purchase_data
		 * @return 			int $payment_id
		 */
		private function insert_payment( $purchase_data ) {
			global $edd_options;

			$payment_data = array(
				'price' => $purchase_data['price'],
				'date' => $purchase_data['date'],
				'user_email' => $purchase_data['user_email'],
				'purchase_key' => $purchase_data['purchase_key'],
				'currency' => $edd_options['currency'],
				'downloads' => $purchase_data['downloads'],
				'user_info' => $purchase_data['user_info'],
				'cart_details' => $purchase_data['cart_details'],
				'status' => 'pending'
			);

			/* record the pending payment */
			$payment = edd_insert_payment( $payment_data );

			return $payment;
		}

		/**
		 * Listen to incoming queries
		 *
		 * @return 			void
		 */
		public function listen() {
			if ( isset( $_REQUEST[ 'verify_payping' ] ) && $_REQUEST[ 'verify_payping' ] ) {
				do_action( 'edd_verify_payping' );
			}
		}


		public function error_reason( $code ) {
			switch ($code){
				case 200 :
					return 'عملیات با موفقیت انجام شد';
					break ;
				case 400 :
					return 'مشکلی در ارسال درخواست وجود دارد';
					break ;
				case 500 :
					return 'مشکلی در سرور رخ داده است';
					break;
				case 503 :
					return 'سرور در حال حاضر قادر به پاسخگویی نمی‌باشد';
					break;
				case 401 :
					return 'عدم دسترسی';
					break;
				case 403 :
					return 'دسترسی غیر مجاز';
					break;
				case 404 :
					return 'آیتم درخواستی مورد نظر موجود نمی‌باشد';
					break;
			}
			return '';
		}
	}

endif;

new EDD_payping_Gateway;