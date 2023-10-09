<?php

/**
 * @license LGPLv3, https://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2023
 */


namespace Aimeos\Upscheme\Task;


class WooMigrateProducts extends Base
{
	private $context;


	public function after() : array
	{
		return ['Product', 'WooMigrateAttributes', 'WooMigrateBrands', 'WooMigrateCategories'];
	}


	public function up()
	{
		$db = $this->db( 'db-woocommerce' );

		if( !$db->hasTable( 'wp_posts' ) ) {
			return;
		}

		$this->info( 'Migrate WooCommerce products', 'vv' );

		$manager = \Aimeos\MShop::create( $this->context(), 'product' );

		$domains = ['attribute', 'catalog', 'media', 'price', 'product', 'product/property', 'stock', 'supplier', 'text'];
		$items = $manager->search( $manager->filter()->slice( 0, 0x7fffffff ), $domains );

		$result = $db->query( "
			SELECT
				p.ID,
				p.post_title,
				p.post_excerpt,
				p.post_content,
				p.post_name,
				p.post_date_gmt,
				t.name AS type,
				pm.sku,
				pm.stock_quantity,
				pm.stock_status
			FROM wp_posts p
			JOIN wp_wc_product_meta_lookup pm ON p.ID = pm.product_id
			JOIN wp_term_relationships tr ON p.ID = tr.object_id
			JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			JOIN wp_terms t ON t.term_id = tt.term_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish' AND tt.taxonomy='product_type'
		" );

		$items = $this->update( $result, $items );

		$result = $db->query( "
			SELECT
				p.ID,
				p.post_parent,
				p.post_title,
				p.post_name,
				p.post_date_gmt,
				pm.sku,
				pm.stock_quantity,
				pm.stock_status
			FROM wp_posts p
			JOIN wp_wc_product_meta_lookup pm ON p.ID = pm.product_id
			WHERE p.post_type = 'product_variation' AND p.post_status = 'publish'
		" );

		$items = $this->update( $result, $items );

		$manager->begin();
		$manager->save( $items );
		$manager->commit();
	}


	protected function context()
	{
		if( !isset( $this->context ) )
		{
			$context = clone 	parent::context();
			$site = $context->config()->get( 'setup/site', 'default' );

			$localeManager = \Aimeos\MShop::create( $context, 'locale', 'Standard' );
			$context->setLocale( $localeManager->bootstrap( $site, '', '', false ) );

			$this->context = $context;
		}

		return $this->context;
	}


	protected function attributes( \Aimeos\Map $items )
	{
		$manager = \Aimeos\MShop::create( $this->context(), 'attribute' );
		$attrMap = $manager->search( $manager->filter()->slice( 0, 0x7fffffff ) )->rekey( function( $item ) {
			return $item->getType() . '/' . $item->getCode();
		} );

		$result = $this->db( 'db-woocommerce' )->query( "
			SELECT p.ID, pm.meta_key, pm.meta_value
			FROM wp_posts p
			JOIN wp_postmeta pm ON p.ID = pm.post_id
			WHERE p.post_type = 'product_variation'
				AND p.post_status = 'publish'
				AND pm.meta_key LIKE 'attribute_%'
		" );

		$manager = \Aimeos\MShop::create( $this->context(), 'product' );

		foreach( $result->iterateAssociative() as $row )
		{
			$len = substr( $row['meta_key'], 10, 3 ) === 'pa_' ? 13 : 10;
			$key = substr( $row['meta_key'], $len ) . '/' . $row['meta_value'];

			if( ( $item = $items->get( $row['ID'] ) ) !== null && ( $attrid = $attrMap[$key]?->getId() ) !== null )
			{
				$listItem = $item->getListItem( 'attribute', 'variant', $attrid ) ?? $manager->createListItem();
				$item->addListItem( 'attribute', $listItem->setType( 'variant' )->setRefId( $attrid ) );
			}
		}
	}


	protected function brands( \Aimeos\Map $items )
	{
		$result = $this->db( 'db-woocommerce' )->query( "
			SELECT p.ID, t.term_id AS brandid, tr.term_order AS pos
			FROM wp_posts p
			JOIN wp_term_relationships tr ON p.ID = tr.object_id
			JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			JOIN wp_terms t ON t.term_id = tt.term_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish' AND tt.taxonomy='brand'
		" );

		$manager = \Aimeos\MShop::create( $this->context(), 'product' );

		foreach( $result->iterateAssociative() as $row )
		{
			if( $item = $items->get( $row['ID'] ) )
			{
				$listItem = $item->getListItem( 'supplier', 'default', $row['brandid'] ) ?? $manager->createListItem();
				$item->addListItem( 'supplier', $listItem->setRefId( $row['brandid'] )->setPosition( $row['pos'] ) );
			}
		}
	}


	protected function categories( \Aimeos\Map $items )
	{
		$result = $this->db( 'db-woocommerce' )->query( "
			SELECT p.ID, t.term_id AS catid, tr.term_order AS pos
			FROM wp_posts p
			JOIN wp_term_relationships tr ON p.ID = tr.object_id
			JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			JOIN wp_terms t ON t.term_id = tt.term_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish' AND tt.taxonomy='product_cat'
		" );

		$manager = \Aimeos\MShop::create( $this->context(), 'product' );

		foreach( $result->iterateAssociative() as $row )
		{
			if( $item = $items->get( $row['ID'] ) )
			{
				$listItem = $item->getListItem( 'catalog', 'default', $row['catid'] ) ?? $manager->createListItem();
				$item->addListItem( 'catalog', $listItem->setRefId( $row['catid'] )->setPosition( $row['pos'] ) );
			}
		}
	}


	protected function images( \Aimeos\Map $items )
	{
		$mediaManager = \Aimeos\MShop::create( $this->context(), 'media' );

		$result = $this->db( 'db-woocommerce' )->query( "
			SELECT p.ID, p.post_title, am.meta_value AS image
			FROM wp_posts p
			JOIN wp_postmeta pm ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id'
			JOIN wp_postmeta am ON am.post_id = pm.meta_value AND am.meta_key = '_wp_attached_file'
			WHERE p.post_type = 'product' AND p.post_status = 'publish'
		" );

		$manager = \Aimeos\MShop::create( $this->context(), 'product' );

		foreach( $result->iterateAssociative() as $row )
		{
			if( $item = $items->get( $row['ID'] ) )
			{
				$listItem = $item->getListItems( 'media', 'default', 'default' )->first() ?? $manager->createListItem();
				$refItem = $listItem->getRefItem() ?: $mediaManager->create();
				$item->addListItem( 'media', $listItem, $refItem->setUrl( $row['image'] )->setLabel( $row['post_title'] ) );
			}
		}
	}


	protected function prices( \Aimeos\Map $items )
	{
		$priceManager = \Aimeos\MShop::create( $this->context(), 'price' );
		$currencyId = $this->context()->locale()->getCurrencyId();
		$db = $this->db( 'db-woocommerce' );

		$taxrate = $db->query( "SELECT tax_rate FROM wp_woocommerce_tax_rates LIMIT 1" )->fetchOne();

		$result = $db->query( "
			SELECT post_id, meta_value
			FROM wp_postmeta
			WHERE meta_key = '_regular_price'
		" );

		$manager = \Aimeos\MShop::create( $this->context(), 'product' );

		foreach( $result->iterateAssociative() as $row )
		{
			if( $item = $items->get( $row['post_id'] ) )
			{
				$listItem = $item->getListItems( 'price', 'default', 'default' )->first() ?? $manager->createListItem();

				$refItem = $listItem->getRefItem() ?: $priceManager->create();
				$refItem->setValue( $row['meta_value'] )->setCurrencyId( $currencyId )->setTaxrate( $taxrate );

				$item->addListItem( 'price', $listItem, $refItem );
			}
		}
	}


	protected function properties( \Aimeos\Map $items )
	{
		$result = $this->db( 'db-woocommerce' )->query( "
			SELECT post_id, meta_key, meta_value
			FROM wp_postmeta
			WHERE meta_key in ('_weight', '_width', '_length', '_height')
		" );

		$manager = \Aimeos\MShop::create( $this->context(), 'product' );

		foreach( $result->iterateAssociative() as $row )
		{
			if( $item = $items->get( $row['post_id'] ) )
			{
				$type = substr( $row['meta_key'], 1 );
				$propItem = $item->getPropertyItems( $type )->first() ?? $manager->createPropertyItem();
				$item->addPropertyItem( $propItem->setType( $type )->setValue( $row['meta_value'] ) );
			}
		}
	}


	protected function suggests( \Aimeos\Map $items )
	{
		$result = $this->db( 'db-woocommerce' )->query( "
			SELECT post_id, meta_value
			FROM wp_postmeta
			WHERE meta_key = '_crosssell_ids'
		" );

		$manager = \Aimeos\MShop::create( $this->context(), 'product' );

		foreach( $result->iterateAssociative() as $row )
		{
			if( ( $item = $items->get( $row['post_id'] ) ) !== null && is_array( $list = unserialize( $row['meta_value'] ) ) )
			{
				foreach( $list as $id )
				{
					$listItem = $item->getListItem( 'product', 'suggest', $id ) ?? $manager->createListItem();
					$item->addListItem( 'product', $listItem->setType( 'suggest' )->setRefId( $id ) );
				}
			}
		}
	}


	protected function type( string $value )
	{
		return match( $value ) {
			'grouped' => 'group',
			'variable' => 'select',
			default => 'default'
		};
	}


	public function update( \Doctrine\DBAL\Result $result, \Aimeos\Map $items ) : \Aimeos\Map
	{
		$stockManager = \Aimeos\MShop::create( $this->context(), 'stock' );
		$textManager = \Aimeos\MShop::create( $this->context(), 'text' );
		$manager = \Aimeos\MShop::create( $this->context(), 'product' );

		$langId = $this->context()->locale()->getLanguageId();
		$db2 = $this->db( 'db-product' );

		foreach( $result->iterateAssociative() as $row )
		{
			$item = $items[$row['ID']] ?? $manager->create();
			$item->setCode( str_replace( ' ', '-', $row['sku'] ) ?: 'woo-' . $row['ID'] )
				->setUrl( $row['post_name'] )
				->setLabel( $row['post_title'] )
				->setType( $this->type( $row['type'] ?? '' ) )
				->setTimeCreated( $row['post_date_gmt'] );

			if( !$item->getId() )
			{
				$item = $manager->save( $item );

				$db2->update( 'mshop_product', ['id' => $row['ID']], ['id' => $item->getId()] );
				$item->setId( $row['ID'] );
			}

			if( $long = ( $row['post_content'] ?? null ) ?: ( $row['post_excerpt'] ?? null ) )
			{
				$listItem = $item->getListItems( 'text', 'default', 'long' )->first() ?? $manager->createListItem();
				$refItem = $listItem->getRefItem() ?: $textManager->create();
				$refItem->setType( 'long' )->setLanguageId( $langId )->setContent( $long )->setLabel( mb_substr( strip_tags( $long ), 0, 60 ) );
				$item->addListItem( 'text', $listItem, $refItem );
			}

			if( $row['stock_status'] )
			{
				$stockItem = $item->getStockItems( 'default' )->first() ?? $stockManager->create();
				$stockManager->save( $stockItem->setProductId( $row['ID'] )->setStockLevel( $row['stock_quantity'] ) );
			}

			if( $parent = $items[$row['post_parent'] ?? null] ?? null )
			{
				$listItem = $parent->getListItem( 'product', 'default', $row['ID'] ) ?? $manager->createListItem();
				$parent->addListItem( 'product', $listItem->setRefId( $row['ID'] ) );
			}

			$items[$item->getId()] = $item;
		}

		$this->properties( $items );
		$this->categories( $items );
		$this->attributes( $items );
		$this->brands( $items );
		$this->images( $items );
		$this->prices( $items );
		$this->suggests( $items );

		return $items;
	}
}
