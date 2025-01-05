<?php

/**
 * @license LGPLv3, https://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2023-2025
 */


namespace Aimeos\Upscheme\Task;


class WooMigrateAttributeTypes extends Base
{
	public function after() : array
	{
		return ['Attribute', 'MShopSetLocale'];
	}


	public function up()
	{
		$db = $this->db( 'db-woocommerce' );

		if( !$db->hasTable( 'wp_terms' ) ) {
			return;
		}

		$this->info( 'Migrate WooCommerce attribute types', 'vv' );

		$manager = \Aimeos\MShop::create( $this->context(), 'attribute/type' );
		$items = $manager->search( $manager->filter()->slice( 0, 0xfffffff ) )->col( null, 'attribute.type.code' );

		$result = $db->query( 'SELECT attribute_name, attribute_label FROM wp_woocommerce_attribute_taxonomies' );

		foreach( $result->iterateAssociative() as $row )
		{
			$item = $items[$row['attribute_name']] ?? $manager->create();
			$item->setCode( $row['attribute_name'] )->setLabel( $row['attribute_label'] )->setDomain( 'product' );

			$manager->save( $item );
		}
	}
}
