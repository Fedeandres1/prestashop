<?php
/**
 * Mobbex.php
 *
 * Main file of the module
 *
 * @author  Mobbex Co <admin@mobbex.com>
 * @version 1.0.0
 * @see     PaymentModuleCore
 */

/**
 * Payment Provider Class
 */
class MobbexHelper
{
    const MOBBEX_VERSION = '1.2.2';

    const K_API_KEY = 'MOBBEX_API_KEY';
    const K_ACCESS_TOKEN = 'MOBBEX_ACCESS_TOKEN';
    const K_TEST_MODE = 'MOBBEX_TEST_MODE';

    // THEMES
    const K_THEME = 'MOBBEX_THEME';
    const K_THEME_BACKGROUND = 'MOBBEX_THEME_BACKGROUND';
    const K_THEME_PRIMARY = 'MOBBEX_THEME_PRIMARY';

    const K_DEF_THEME = true;
    const K_DEF_BACKGROUND = '#ECF2F6';
    const K_DEF_PRIMARY = '#6f00ff';

    const K_OS_PENDING = 'MOBBEX_OS_PENDING';
    const K_OS_WAITING = 'MOBBEX_OS_WAITING';
    const K_OS_REJECTED = 'MOBBEX_OS_REJECTED';

    public static function getUrl($path)
    {
        return Tools::getShopDomain(true, true).__PS_BASE_URI__.$path;
    }

    public static function getModuleUrl($controller, $action, $path)
    {
        // controller / module / fc
        // controller=notification
        // module=mobbex
        // fc=module
        return MobbexHelper::getUrl('index.php?controller='.$controller.'&module=mobbex&fc=module&action='.$action.$path);
    }

    public static function getWebhookUrl($params) {
        return Context::getContext()->link->getModuleLink(
            'mobbex',
            'webhook',
            $params,
            true
        );
    }

    public static function getPlatform() {
        return array(
            "name" => "prestashop",
            "verison" => MobbexHelper::MOBBEX_VERSION,
            "platform_version" => _PS_VERSION_
        );
    }

    public static function getHeaders()
    {
        return array(
            'cache-control: no-cache',
            'content-type: application/x-www-form-urlencoded',
            'x-access-token: '. Configuration::get(MobbexHelper::K_ACCESS_TOKEN),
            'x-api-key: '.Configuration::get(MobbexHelper::K_API_KEY)
        );
    }

    public static function getOptions()
    {
        $theme = array(
            "type" => Configuration::get(MobbexHelper::K_THEME) ? 'light' : 'dark'
        );

        $theme_background = Configuration::get(MobbexHelper::K_THEME_BACKGROUND);
        $theme_primary = Configuration::get(MobbexHelper::K_THEME_PRIMARY);

        if(isset($theme_background) && $theme_background != '') {
            array_merge($theme, array(
                "background" => $theme_background
            ));
        }

        if(isset($theme_primary) && $theme_primary != '') {
            array_merge($theme, array(
                "colors" => array(
                    "primary" => $theme_primary
                )
            ));
        }

        $options = array(
            "theme" => $theme,
            "platform" => MobbexHelper::getPlatform()
        );

        return $options;
    }

    public static function getReference($customer, $cart)
    {
        return 'ps_order_customer_'.$customer->id.'_cart_'.$cart->id.'_seed_'.mt_rand(100000, 999999);
    }

    public static function createCheckout($module, $cart, $customer)
    {
        $curl = curl_init();

        // Create an unique id
        $tracking_ref = MobbexHelper::getReference($customer, $cart);

        $items = array();
        $products = $cart->getProducts(true);

        //p($products);

        foreach($products as $product) {
            //p($product);
            $image = Image::getCover($product['id_product']);
            $link = new Link; //because getImageLInk is not static function
            $imagePath = $link->getImageLink($product['link_rewrite'], $image['id_image'], 'home_default');

            $items[] = array("image" => 'https://'.$imagePath, "description" => $product['name'], "quantity" => $product['cart_quantity'], "total" => round($product['price_wt'],2) );
        }

        // Create data
        $data = array(
            'reference' => $tracking_ref,
            'currency' => 'ARS',
            'email' => $customer->email,
            'description' => 'Orden #'.$cart->id,
            // Test Mode
            'test' => Configuration::get(MobbexHelper::K_TEST_MODE),
            // notification / return => '&id_cart='.$cart->id.'&customer_id='.$customer->id
            'return_url' => MobbexHelper::getModuleUrl('notification', 'return', '&id_cart='.$cart->id.'&customer_id='.$customer->id),
            // notification / hook => '&id_cart='.$cart->id.'&customer_id='.$customer->id.'&key='.$customer->secure_key
            'items' => $items,
            //MobbexHelper::getModuleUrl('notification', 'hook', '&id_cart='.$cart->id.'&customer_id='.$customer->id.'&key='.$customer->secure_key),
            'webhook' => MobbexHelper::getWebhookUrl(array(
                "id_cart" => $cart->id,
                "customer_id" => $customer->id,
                "key" => $customer->secure_key
            )),
            'options' => MobbexHelper::getOptions(),
            'redirect' => 0,
            'total' => (float)$cart->getOrderTotal(true, Cart::BOTH),
        );

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://mobbex.com/p/checkout/create",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => self::getHeaders(),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            d("cURL Error #:" . $err);
        } else {
            $res = json_decode($response, true);

            return $res['data']['url'];
        }
    }

    /**
     * Get the payment URL
     *
     * @return string
     */
    public static function getPaymentUrl()
    {
        $module = Context::getContext()->controller->module;
        $cart = Context::getContext()->cart;
        $customer = Context::getContext()->customer;

        return MobbexHelper::createCheckout($module, $cart, $customer);
    }

    public static function evaluateTransactionData($res)
    {
        // Get the Status
        $status = $res['payment']['status']['code'];

        // Get the Reference ( Transaction ID )
        $transaction_id = $res['payment']['id'];

        $source_type = $res['payment']['source']['type'];
        $source_name = $res['payment']['source']['name'];

        $message = $res['payment']['status']['message'];

        // Create Result Array
        $result = array(
            'status' => (int) Configuration::get(MobbexHelper::K_OS_WAITING),
            'message' => $message,
            'name' => $source_name,
            'transaction_id' => $transaction_id,
            'source_type' => $source_type,
            'data' => $res
        );

        if ($status == 200) {
            $result['status'] = (int) Configuration::get('PS_OS_PAYMENT');
        } elseif ($status == 1 && $source_type != 'card') {
            $result['status'] = (int) Configuration::get(MobbexHelper::K_OS_PENDING);
        } elseif ($status == 2 && $source_type != 'card') {
            $result['status'] = (int) Configuration::get(MobbexHelper::K_OS_WAITING);
        } else {
            $result['status'] = (int) Configuration::get(MobbexHelper::K_OS_REJECTED);
        }

        return $result;
    }

    public static function getTransaction($context, $transaction_id)
    {
        $curl = curl_init();

        // Create data
        $data = array(
            'id' => $transaction_id
        );

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://mobbex.com/2.0/transactions/status",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => self::getHeaders(),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            echo "cURL Error #:" . $err;
        } else {
            $res = json_decode($response, true);

            return self::evaluateTransactionData($res['data']['transaction']);
        }
    }
}
