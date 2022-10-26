<?php

/**
 * @license LGPLv3, https://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2022
 */


namespace Aimeos\MShop\Service\Provider\Decorator;


class LogstaTest extends \PHPUnit\Framework\TestCase
{
	private $object;
	private $context;
	private $servItem;
	private $stubProvider;


	protected function setUp() : void
	{
		\Aimeos\MShop::cache( true );

		$this->context = \TestHelper::context();

		$servManager = \Aimeos\MShop::create( $this->context, 'service' );
		$this->servItem = $servManager->create()->setConfig( [
			'logsta.username' => 'user',
			'logsta.password' => 'test',
			'logsta.apikey' => 'abcd',
			'logsta.sellerid' => '123',
		] );

		$this->stubProvider = $this->getMockBuilder( \Aimeos\MShop\Service\Provider\Decorator\Example::class )
			->disableOriginalConstructor()->getMock();

		$this->object = $this->getMockBuilder( \Aimeos\MShop\Service\Provider\Decorator\Logsta::class )
			->setConstructorArgs( [$this->stubProvider, $this->context, $this->servItem] )
			->setMethods( ['send'] )
			->getMock();
	}


	protected function tearDown() : void
	{
		\Aimeos\MShop::cache( false );
		unset( $this->object, $this->stubProvider, $this->servItem, $this->context );
	}


	public function testGetConfigBE()
	{
		$this->stubProvider->expects( $this->once() )->method( 'getConfigBE' )->will( $this->returnValue( [] ) );

		$result = $this->object->getConfigBE();

		$this->assertArrayHasKey( 'logsta.username', $result );
		$this->assertArrayHasKey( 'logsta.password', $result );
		$this->assertArrayHasKey( 'logsta.apikey', $result );
		$this->assertArrayHasKey( 'logsta.sellerid', $result );
		$this->assertArrayHasKey( 'logsta.shippingServiceGroupId', $result );
	}


	public function testCheckConfigBEOK()
	{
		$this->stubProvider->expects( $this->once() )
			->method( 'checkConfigBE' )
			->will( $this->returnValue( [] ) );

		$attributes = [
			'logsta.username' => 'user',
			'logsta.password' => 'test',
			'logsta.apikey' => 'abcd',
			'logsta.sellerid' => '123',
		];
		$result = $this->object->checkConfigBE( $attributes );

		$this->assertEquals( 5, count( $result ) );
		$this->assertNull( $result['logsta.username'] );
		$this->assertNull( $result['logsta.password'] );
		$this->assertNull( $result['logsta.apikey'] );
		$this->assertNull( $result['logsta.sellerid'] );
		$this->assertNull( $result['logsta.shippingServiceGroupId'] );
	}


	public function testCalcPrice()
	{
		$price = \Aimeos\MShop::create( $this->context, 'price' )->create();

		$this->stubProvider->expects( $this->once() )->method( 'calcPrice' )
			->will( $this->returnValue( $price ) );

		$this->object->expects( $this->exactly( 2 ) )->method( 'send' )
			->will( $this->onConsecutiveCalls(
				[['token' => 'abcd'], 200],
				[['estimateResults' => [['success' => true, 'amountLabel' => '10.00']]], 200]
			) );

		$this->assertEquals( '10.00', $this->object->calcPrice( $this->getOrderBaseItem() )->getCosts() );
	}


	/**
	 * @return \Aimeos\MShop\Order\Item\Base\Iface
	 */
	protected function getOrderBaseItem()
	{
		$manager = \Aimeos\MShop::create( $this->context, 'order' );
		$search = $manager->filter()->add( ['order.datepayment' => '2008-02-15 12:34:56'] );

		$ref = ['order/base', 'order/base/address', 'order/base/product'];
		return $manager->search( $search, $ref )->first( new \RuntimeException( 'No order item found' ) )->getBaseItem();
	}
}