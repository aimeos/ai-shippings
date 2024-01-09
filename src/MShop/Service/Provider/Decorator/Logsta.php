<?php

/**
 * @license LGPLv3, https://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2022-2024
 * @package MShop
 * @subpackage Service
 */


namespace Aimeos\MShop\Service\Provider\Decorator;


/**
 * Decorator for calculating shipping costs using Logsta API
 *
 * @package MShop
 * @subpackage Service
 */
class Logsta
	extends \Aimeos\MShop\Service\Provider\Decorator\Base
	implements \Aimeos\MShop\Service\Provider\Decorator\Iface
{
	private string $url = 'https://api.logsta.com/shipments/estimate';

	private array $beConfig = [
		'logsta.username' => [
			'code' => 'logsta.username',
			'internalcode' => 'logsta.username',
			'label' => 'Logsta user name',
			'type' => 'string',
			'internaltype' => 'string',
			'default' => '',
			'required' => true,
		],
		'logsta.password' => [
			'code' => 'logsta.password',
			'internalcode' => 'logsta.password',
			'label' => 'Logsta password',
			'type' => 'string',
			'internaltype' => 'string',
			'default' => '',
			'required' => true,
		],
		'logsta.apikey' => [
			'code' => 'logsta.apikey',
			'internalcode' => 'logsta.apikey',
			'label' => 'Logsta API key',
			'type' => 'string',
			'internaltype' => 'string',
			'default' => '',
			'required' => true,
		],
		'logsta.sellerid' => [
			'code' => 'logsta.sellerid',
			'internalcode' => 'logsta.sellerid',
			'label' => 'Logsta seller ID',
			'type' => 'string',
			'internaltype' => 'string',
			'default' => '',
			'required' => true,
		],
		'logsta.shippingServiceGroupId' => [
			'code' => 'logsta.shippingServiceGroupId',
			'internalcode' => 'logsta.shippingServiceGroupId',
			'label' => 'Logsta ID of the shipping service group',
			'type' => 'int',
			'internaltype' => 'integer',
			'default' => 0,
			'required' => false,
		],
	];


	/**
	 * Checks the backend configuration attributes for validity.
	 *
	 * @param array $attributes Attributes added by the shop owner in the administraton interface
	 * @return array An array with the attribute keys as key and an error message as values for all attributes that are
	 * 	known by the provider but aren't valid
	 */
	public function checkConfigBE( array $attributes ) : array
	{
		$error = $this->getProvider()->checkConfigBE( $attributes );
		$error += $this->checkConfig( $this->beConfig, $attributes );

		return $error;
	}


	/**
	 * Returns the configuration attribute definitions of the provider to generate a list of available fields and
	 * rules for the value of each field in the administration interface.
	 *
	 * @return array List of attribute definitions implementing \Aimeos\Base\Critera\Attribute\Iface
	 */
	public function getConfigBE() : array
	{
		return array_merge( $this->getProvider()->getConfigBE(), $this->getConfigItems( $this->beConfig ) );
	}


	/**
	 * Returns the price when using the provider.
	 *
	 * @param \Aimeos\MShop\Order\Item\Iface $basket Basket object
	 * @param array $options Selected options by customer from frontend
	 * @return \Aimeos\MShop\Price\Item\Iface Price item containing the price, shipping, rebate
	 */
	public function calcPrice( \Aimeos\MShop\Order\Item\Iface $basket, array $options = [] ) : \Aimeos\MShop\Price\Item\Iface
	{
		$price = $this->getProvider()->calcPrice( $basket, $options );

		return $price->setCosts( $price->getCosts() + $this->getCosts( $basket ) );
	}


	/**
	 * Returns the shipping costs
	 *
	 * @param \Aimeos\MShop\Order\Item\Iface $basket Basket object
	 * @return float Shipping costs
	 */
	protected function getCosts( \Aimeos\MShop\Order\Item\Iface $basket ) : float
	{
		$address = current( $basket->getAddress( 'delivery' ) ) ?: current( $basket->getAddress( 'payment' ) );

		if( $address === false ) {
			return 0;
		}

		$quantities = $this->getQuantities( $basket );
		$key = 'logsta/costs/' . md5( json_encode( $quantities ) );

		$session = $this->context()->session();

		if( ( $costs = $session->get( $key ) ) === null )
		{
			$weight = $this->getWeight( $quantities );
			$costs = $this->estimate( $address, $weight );
		}

		$session->set( $key, $costs );

		return $costs;
	}


	/**
	 * Returns the product quantities
	 *
	 * @param \Aimeos\MShop\Order\Item\Iface $basket Basket object
	 * @return array Associative list of product codes as keys and quantities as values
	 */
	protected function getQuantities( \Aimeos\MShop\Order\Item\Iface $basket ) : array
	{
		$prodMap = [];

		// basket can contain a product several times in different basket items
		foreach( $basket->getProducts() as $orderProduct )
		{
			$code = $orderProduct->getProductCode();
			$prodMap[$code] = ( $prodMap[$code] ?? 0 ) + $orderProduct->getQuantity();

			foreach( $orderProduct->getProducts() as $prodItem ) // calculate bundled products
			{
				$code = $prodItem->getProductCode();
				$prodMap[$code] = ( $prodMap[$code] ?? 0 ) + $prodItem->getQuantity();
			}
		}

		return $prodMap;
	}


	/**
	 * Returns the weight of the products
	 *
	 * @param array $prodMap Associative list of product codes as keys and quantities as values
	 * @return float Sumed up product weight multiplied with its quantity
	 */
	protected function getWeight( array $prodMap ) : float
	{
		$weight = 0;
		$manager = \Aimeos\MShop::create( $this->context(), 'product' );
		$search = $manager->filter()->add( ['product.code' => array_keys( $prodMap )] )->slice( 0, count( $prodMap ) );

		foreach( $manager->search( $search, ['product/property' => ['package-weight']] ) as $product )
		{
			foreach( $product->getProperties( 'package-weight' ) as $value ) {
				$weight += $value * $prodMap[$product->getCode()];
			}
		}

		return $weight;
	}


	/**
	 * Requests the actual shipping costs from the Logsta API
	 *
	 * @param \Aimeos\MShop\Common\Item\Address\Iface $address Delivery address
	 * @param float $weight Total weight of the package
	 * @return float Shipping costs
	 */
	protected function estimate( \Aimeos\MShop\Common\Item\Address\Iface $address, float $weight ) : float
	{
		if( ( $sellerId = $this->getConfigValue( 'logsta.sellerid' ) ) === null )
		{
			$msg = $this->context()->translate( 'mshop', 'Missing configuration "%1$s"' );
			throw new \Aimeos\MShop\Service\Exception( sprintf( $msg, 'logsta.sellerid' ) );
		}

		$groupId = $this->getConfigValue( 'logsta.shippingServiceGroupId', 0 );
		$payload = [
			'estimateRequests' => [[
				'requestUUID' => $this->context()->token(),
				'shippingServiceGroupId' => $groupId,
				'grossWeightKg' => $weight,
				'sellerId' => $sellerId,
				'shipTo' => [
					'zip' => $address->getPostal(),
					'city' => $address->getCity(),
					'street' => $address->getAddress1(),
					'street2' => $address->getAddress2(),
					'countryIso2' => $address->getCountryId()
				]
			]]
		];

		list( $result, $code ) = $this->send( $this->url, $payload, ['Authorization' => $this->token()] );

		if( ( $result['estimateResults'][0]['success'] ?? false ) === false )
		{
			$msg = $this->context()->translate( 'mshop', 'Requesting shipping costs failed' );
			throw new \Aimeos\MShop\Service\Exception( $msg );
		}

		return ( $result['estimateResults'][0]['amountLabel'] ?? 0 )
			+ ( $result['estimateResults'][0]['amountInsurance'] ?? 0 );
	}


	/**
	 * Sends a request to the Logsta API
	 *
	 * @param string $url URL of the Logsta API endpoint
	 * @param array $payload Payload of the request
	 * @param array Associative list of HTTP headers
	 * @return array Logsta API response
	 */
	protected function send( string $url, array $payload, array $headers = [] )
	{
		if( ( $apikey = $this->getConfigValue( 'logsta.apikey' ) ) === null )
		{
			$msg = $this->context()->translate( 'mshop', 'Missing configuration "%1$s"' );
			throw new \Aimeos\MShop\Service\Exception( sprintf( $msg, 'logsta.apikey' ) );
		}

		if( ( $ch = curl_init() ) === false ) {
			throw new \RuntimeException( 'Initializing CURL connection failed' );
		}

		$headers['X-Api-Key'] = $apikey;
		$headers['Content-Type'] = 'application/json';
		$body = json_encode( $payload );

		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 5 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 5 );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
		curl_setopt( $ch, CURLOPT_POST, true );

		if( ( $response = curl_exec( $ch ) ) === false ) {
			throw new \RuntimeException( sprintf( 'Curl exec failed for "%1$s": %2$s', $url, curl_error( $ch ) ) );
		}

		if( ( $errno = curl_errno( $ch ) ) !== 0 ) {
			throw new \RuntimeException( sprintf( 'Curl error for "%1$s": "%2$s"', $url, curl_error( $ch ) ) );
		}

		if( ( $httpcode = curl_getinfo( $ch, CURLINFO_HTTP_CODE ) ) === false ) {
			throw new \RuntimeException( sprintf( 'Curl getinfo failed for "%1$s": %2$s', $url, curl_error( $ch ) ) );
		}

		curl_close( $ch );

		if( ( $result = json_decode( $response, true ) ) === null || !is_array( $result ) ) {
			throw new \RuntimeException( sprintf( 'Invalid repsonse for "%1$s": %2$s', $url, $response ) );
		}

		return [$result, $httpcode];
	}


	/**
	 * Returns the token for Logsta API requests
	 *
	 * @return string API token
	 */
	protected function token() : string
	{
		$session = $this->context()->session();

		if( ( $token = $session->get( 'logsta/token/value' ) ) && $session->get( 'logsta/token/until' ) < time() ) {
			return $token;
		}

		list( $result, $code ) = $this->send( 'https://api.logsta.com/login', [
			'username' => $this->getConfigValue( 'logsta.username' ),
			'password' => $this->getConfigValue( 'logsta.password' ),
		] );

		if( $code != 200 || !isset( $result['token'] ) )
		{
			$msg = $this->context()->translate( 'mshop', 'Logsta login for requesting shipping costs failed' );
			throw new \Aimeos\MShop\Service\Exception( $msg );
		}

		$session->set( 'logsta/token/value', $result['token'] );
		$session->set( 'logsta/token/until', time() + 3600 );

		return $result['token'];
	}
}
