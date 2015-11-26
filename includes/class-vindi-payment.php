<?php

class Vindi_Payment
{
    /**
     * Order type is invalid.
     */
    const ORDER_TYPE_INVALID = 0;

    /**
     * Order type is Subscription Payment.
     */
    const ORDER_TYPE_SUBSCRIPTION = 1;

    /**
     * Order type is Single Payment.
     */
    const ORDER_TYPE_SINGLE = 2;

    /**
     * Order that will be paid;
     * @var WC_Order
     */
    protected $order;

    /**
     * Vindi Gateway.
     * @var Vindi_Settings
     */
    protected $container;

    /**
     * @param WC_Order           $order
     * @param Vindi_Base_Gateway $gateway
     * @param Vindi_Settings     $container
     */
    function __construct(WC_Order $order, Vindi_Base_Gateway $gateway, Vindi_Settings $container)
    {
        $this->order     = $order;
        $this->gateway   = $gateway;
        $this->container = $container;
    }

    /**
     * Validate order to chose payment type.
     * @return int order type.
     */

    public function validate_order()
    {
        $items = $this->order->get_items();

        foreach ($items as $item) {
            $product = $this->order->get_product_from_item($item);

            if ($product->is_type('subscription')) {
                if (1 === count($items))
                    return static::ORDER_TYPE_SUBSCRIPTION;

                return static::ORDER_TYPE_INVALID;
            }
        }

        return static::ORDER_TYPE_SINGLE;
    }

    /**
     * Retrieve Plan for Vindi Subscription.
     * @return int|bool
     */
    public function get_plan()
    {
        $items      = $this->order->get_items();
        $item       = array_shift($items); //get only the first item
        $product    = $this->order->get_product_from_item($item);
        $vindi_plan = get_post_meta($product->id, 'vindi_subscription_plan', true);

        if (! $product->is_type('subscription') || empty($vindi_plan))
            $this->abort(__('O produto selecionado não é uma assinatura.', VINDI_IDENTIFIER), true);

        return $vindi_plan;
    }

    /**
     * Find or Create a Customer at Vindi for the given credentials.
     * @return array|bool
     */
    public function get_customer()
    {
        $currentUser = wp_get_current_user();
        $email       = $this->order->billing_email;

        $address = array(
            'street'             => $this->order->billing_address_1,
            'number'             => $this->order->billing_number,
            'additional_details' => $this->order->billing_address_2,
            'zipcode'            => $this->order->billing_postcode,
            'neighborhood'       => $this->order->billing_neighborhood,
            'city'               => $this->order->billing_city,
            'state'              => $this->order->billing_state,
            'country'            => $this->order->billing_country,
        );

        $user_id = $currentUser->ID;

        if (! $userCode = get_user_meta($user_id, 'vindi_user_code', true)) {
            $userCode = 'wc-' . $user_id . '-' . time();
            add_user_meta($user_id, 'vindi_user_code', $userCode, true);
        }

        $metadata = array();

        if ('2' === $this->order->billing_persontype) {
            // Pessoa jurídica
            $name        = $this->order->billing_company;
            $cpf_or_cnpj = $this->order->billing_cnpj;
            $notes       = sprintf('Nome: %s %s', $this->order->billing_first_name, $this->order->billing_last_name);
            if ($this->container->send_nfe_information())
                $metadata['inscricao_estadual'] = $this->order->billing_ie;

        } else {
            // Pessoa física
            $name      = $this->order->billing_first_name . ' ' . $this->order->billing_last_name;
            $cpfOrCnpj = $this->order->billing_cpf;
            $notes     = '';
            if ($this->container->send_nfe_information()) {
                $metadata['carteira_de_identidade'] = $this->order->billing_rg;
            }
        }

        $customer = array(
            'name'          => $name,
            'email'         => $email,
            'registry_code' => $cpfOrCnpj,
            'code'          => $userCode,
            'address'       => $address,
            'notes'         => $notes,
            'metadata'      => $metadata,
        );

        $customer_id = $this->container->api->find_or_create_customer($customer);

        if (false === $customer_id)
            $this->abort(__('Falha ao registrar o usuário. Verifique os dados e tente novamente.', VINDI_IDENTIFIER ), true);

        $this->container->logger->log(sprintf('Cliente Vindi: %s', $customer_id));

        if ($this->is_cc())
            $this->create_payment_profile($customer_id);

        return $customer_id;
    }

    /**
     * Build payment type for credit card.
     *
     * @param int $customer_id
     *
     * @return array
     */
    public function get_cc_payment_type($customer_id)
    {
        return array(
            'customer_id'     => $customer_id,
            'holder_name'     => $_POST['vindi_cc_fullname'],
            'card_expiration' => $_POST['vindi_cc_monthexpiry'] . '/' . $_POST['vindi_cc_yearexpiry'],
            'card_number'     => $_POST['vindi_cc_number'],
            'card_cvv'        => $_POST['vindi_cc_cvc'],
        );
    }

    /**
     * Check if payment is of type "Credit Card"
     * @return bool
     */
    public function is_cc()
    {
        return 'cc' === $this->gateway->type();
    }

    /**
     * Check if payment is of type "Invoice"
     * @return bool
     */
    public function is_invoice()
    {
        return 'invoice' === $this->gateway->type();
    }

    /**
     * @return string
     */
    public function payment_method_code()
    {
        // TODO fix it to proper method code
        return $this->is_cc() ? 'credit_card' : 'bank_slip';
    }

    /**
     * @param string $message
     * @param bool   $throw_exception
     *
     * @return bool
     * @throws Exception
     */
    public function abort($message, $throw_exception = false)
    {
        $this->container->logger->log($message);
        $this->order->add_order_note($message);
        wc_add_notice($message, 'error');
        if ($throw_exception)
            throw new Exception($message);

        return false;
    }

    /**
     * @return array|void
     * @throws Exception
     */
    public function process()
    {
        switch ($orderType = $this->validate_order()) {
            case static::ORDER_TYPE_SINGLE:
                return $this->process_single_payment();
            case static::ORDER_TYPE_SUBSCRIPTION:
                return $this->process_subscription();
            case static::ORDER_TYPE_INVALID:
            default:
                return $this->abort(__('Falha ao processar carrinho de compras. Verifique os itens escolhidos e tente novamente.', VINDI_IDENTIFIER), true);
        }
    }

    /**
     * @return array
     * @throws Exception
     */
    public function process_subscription()
    {
        $customer_id  = $this->get_customer();
        $subscription = $this->create_subscription($customer_id);
        add_post_meta($this->order->id, 'vindi_wc_cycle', $subscription['current_period']['cycle']);
        add_post_meta($this->order->id, 'vindi_wc_subscription_id', $subscription['id']);
        $this->add_download_url_meta_for_subscription($subscription);

        return $this->finish_payment();
    }

    /**
     * @return array
     * @throws Exception
     */
    public function process_single_payment()
    {
        $customer_id = $this->get_customer();
        $bill_id     = $this->create_bill($customer_id);
        add_post_meta($this->order->id, 'vindi_wc_bill_id', $bill_id);
        $this->add_download_url_meta_for_single_payment($bill_id);

        return $this->finish_payment();
    }

    /**
     * @param int $customer_id
     *
     * @throws Exception
     */
    protected function create_payment_profile($customer_id)
    {
        $cc_info            = $this->get_cc_payment_type($customer_id);
        $payment_profile_id = $this->container->api->create_customer_payment_profile($cc_info);
        if (false === $payment_profile_id)
            $this->abort(__('Falha ao registrar o método de pagamento. Verifique os dados e tente novamente.', VINDI_IDENTIFIER), true);
    }

    /**
     * @param int   $vindi_plan
     * @param float $order_total
     *
     * @return array|bool
     * @throws Exception
     */
    protected function get_product_items($vindi_plan, $order_total)
    {
        $product_items = $this->container->api->build_plan_items_for_subscription($vindi_plan, $order_total);

        if (empty($product_items))
            return $this->abort(__('Falha ao recuperar informações sobre o produto na Vindi. Verifique os dados e tente novamente.', VINDI_IDENTIFIER), true);

        return $product_items;
    }

    /**
     * @return array
     * @throws Exception
     */
    protected function build_product_items()
    {
        $product_items = [];
        $order_type    = 'bill';

        $order_items = $this->order->get_items();

        foreach ($order_items as $key => $order_item) {
            $product             = wc_get_product($order_item['product_id']);
            $item                = $this->container->api->find_or_create_product($product->get_title(), sanitize_title($product->get_title()));

            if($product->is_type('subscription'))
                $order_type = 'subscription';

            $order_items[$key]['type']     = 'product';
            $order_items[$key]['vindi_id'] = $item['id'];
            $order_items[$key]['price']    = $product->get_price();
        }

        if ($shipping_method = $this->order->get_shipping_method()) {
            $item            = $this->container->api->find_or_create_product("Frete ($shipping_method)", sanitize_title($shipping_method));
            $order_items[] = array(
                            'type'     => 'shipping',
                            'vindi_id' => $item['id'],
                            'price'    => $this->order->get_total_shipping(),
                            'qty'      => 1,
                        );
        }

        $total_discount = $this->order->get_total_discount();
        if('bill' == $order_type && !empty($total_discount)) {
            $item          = $this->container->api->find_or_create_product("Cupom de desconto", 'wc-discount');
            $order_items[] = array(
                            'type'     => 'discount',
                            'vindi_id' => $item['id'],
                            'price'    => $total_discount * -1,
                            'qty'      => 1,
                        );
        }

        foreach ($order_items as $order_item) {
            if($order_type == 'subscription') {
                if(!empty($total_discount) && $order_item['type'] == 'product') {
                    $product_items[] = array(
                        'product_id' => $order_item['vindi_id'],
                        'quantity'   => $order_item['qty'],
                        'pricing_schema' => array(
                            'price'         => $order_item['price'],
                            'schema_type'   => 'flat'
                        ),
                        'discounts' => array(
                            array(
                                'discount_type' => 'amount',
                                'amount'        => $total_discount
                            )
                        )
                    );
                } else {
                    $product_items[] = array(
                        'product_id' => $order_item['vindi_id'],
                        'quantity'   => $order_item['qty'],
                        'pricing_schema' => array(
                            'price'         => $order_item['price'],
                            'schema_type'   => 'flat'
                        )
                    );
                }
            } else {
                for($i=0 ; $i<$order_item['qty'] ; $i++) {
                    $product_items[]     = array(
                        'product_id' => $order_item['vindi_id'],
                        'amount'     => $order_item['price'],
                    );
                }
            }
        }

        if (empty($product_items))
            return $this->abort(__('Falha ao recuperar informações sobre o produto na Vindi. Verifique os dados e tente novamente.', VINDI_IDENTIFIER), true);

        return $product_items;
    }

    /**
     * @param $customer_id
     *
     * @return array
     * @throws Exception
     */
    protected function create_subscription($customer_id)
    {
        $vindi_plan            = $this->get_plan();
        $wc_subscription_array = wcs_get_subscriptions_for_order($this->order->id);
        $wc_subscription       = end($wc_subscription_array);

        $body = array(
            'customer_id'         => $customer_id,
            'payment_method_code' => $this->payment_method_code(),
            'plan_id'             => $vindi_plan,
            'product_items'       => $this->build_product_items(),
            'code'                => $wc_subscription->id,
        );

        $subscription = $this->container->api->create_subscription($body);

        if (! isset($subscription['id']) || empty($subscription['id'])) {
            $this->container->logger->log(sprintf('Erro no pagamento do pedido %s.', $this->order->id));

            $message = sprintf(__('Pagamento Falhou. (%s)', VINDI_IDENTIFIER), $this->container->api->last_error);
            $this->order->update_status('failed', $message);

            throw new Exception($message);
        }

        return $subscription;
    }

    /**
     * @param int $customer_id
     *
     * @return int
     * @throws Exception
     */
    protected function create_bill($customer_id)
    {
        $body = array(
            'customer_id'         => $customer_id,
            'payment_method_code' => $this->payment_method_code(),
            'bill_items'          => $this->build_product_items(),
            'code'                => $this->order->id,
        );

        if ('credit_card' === $this->payment_method_code() && isset($_POST['vindi_cc_installments']))
            $body['installments'] = (int) $_POST['vindi_cc_installments'];

        $bill_id = $this->container->api->create_bill($body);

        if (! $bill_id) {
            $this->container->logger->log(sprintf('Erro no pagamento do pedido %s.', $this->order->id));
            $message = sprintf(__('Pagamento Falhou. (%s)', VINDI_IDENTIFIER), $this->container->api->last_error);
            $this->order->update_status('failed', $message);

            throw new Exception($message);
        }

        return $bill_id;
    }

    /**
     * @param $subscription
     */
    protected function add_download_url_meta_for_subscription($subscription)
    {
        if (isset($subscription['bill'])) {
            $bill         = $subscription['bill'];
            $download_url = false;

            if ('review' === $bill['status']) {
                $this->container->api->approve_bill($bill['id']);
                $download_url = $this->container->api->get_bank_slip_download($bill['id']);
            } elseif (isset($bill['charges']) && count($bill['charges'])) {
                $download_url = $bill['charges'][0]['print_url'];
            }

            if ($download_url)
                add_post_meta($this->order->id, 'vindi_wc_invoice_download_url', $download_url);
        }
    }

    /**
     * @param int $bill_id
     */
    protected function add_download_url_meta_for_single_payment($bill_id)
    {
        $download_url = false;

        if ($this->container->api->approve_bill($bill_id))
            $download_url = $this->container->api->get_bank_slip_download($bill_id);

        if ($download_url)
            add_post_meta($this->order->id, 'vindi_wc_invoice_download_url', $download_url);
    }

    /**
     * @return array
     */
    protected function finish_payment()
    {
        $this->container->woocommerce->cart->empty_cart();

        $data_to_log    = sprintf('Aguardando confirmação de recebimento do pedido %s pela Vindi.', $this->order->id);
        $status_message = __('Aguardando confirmação de recebimento do pedido pela Vindi.', VINDI_IDENTIFIER);
        $status         = 'pending';

        if (! $this->is_cc()) {
            $data_to_log    = sprintf('Aguardando pagamento do boleto do pedido %s.', $this->order->id);
            $status_message = __('Aguardando pagamento do boleto do pedido', VINDI_IDENTIFIER);
        }

        $this->container->logger->log($data_to_log);
        $this->order->update_status($status, $status_message);

        return array(
            'result'   => 'success',
            'redirect' => $this->order->get_checkout_order_received_url(),
        );
    }
}
