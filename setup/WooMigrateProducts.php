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

		$textManager = \Aimeos\MShop::create( $this->context(), 'text' );
		$manager = \Aimeos\MShop::create( $this->context(), 'product' );
		$items = $manager->search( $manager->filter()->slice( 0, 0x7fffffff ), ['catalog', 'media', 'product', 'text'] );

		$db2 = $this->db( 'db-product' );

		$result = $db->query( "
			SELECT
				p.ID,
				p.post_title,
				p.post_excerpt,
				p.post_content,
				p.post_name,
				p.post_date_gmt,
				t.name AS type,
				sku.meta_value AS sku
			FROM wp_posts p
			LEFT JOIN wp_postmeta sku ON p.ID = sku.post_ID AND sku.meta_key = '_sku'
			JOIN wp_term_relationships tr ON p.ID = tr.object_id
			JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			JOIN wp_terms t ON t.term_id = tt.term_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish' AND tt.taxonomy='product_type'
		" );

		foreach( $result->iterateAssociative() as $row )
		{
			$item = $items[$row['ID']] ?? $manager->create();
			$item->setCode( str_replace( ' ', '-', $row['sku'] ) ?: $row['ID'] )
				->setUrl( $row['post_name'] )
				->setLabel( $row['post_title'] )
				->setType( $this->type( $row['type'] ) )
				->setTimeCreated( $row['post_date_gmt'] );

			if( !$item->getId() )
			{
				$item = $manager->save( $item );

				$db2->update( 'mshop_product', ['id' => $row['ID']], ['id' => $item->getId()] );
				$item->setId( $row['ID'] );
			}

			if( $long = $row['post_content'] ?: $row['post_excerpt'] )
			{
				$listItem = $item->getListItems( 'text', 'default', 'long' )->first() ?? $manager->createListItem();
				$refItem = $listItem->getRefItem() ?: $textManager->create();
				$item->addListItem( 'text', $listItem, $refItem->setContent( $long ) );
			}

			$items[$item->getId()] = $item;
		}

		$this->properties( $db, $manager, $items );
		$this->categories( $db, $manager, $items );
		$this->attributes( $db, $manager, $items );
		$this->brands( $db, $manager, $items );
		$this->images( $db, $manager, $items );
		$this->prices( $db, $manager, $items );
		$this->suggests( $db, $manager, $items );

		$manager->save( $items );
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


	protected function attributes( \Aimeos\Upscheme\Schema\DB $db, \Aimeos\MShop\Common\Manager\Iface $manager, \Aimeos\Map $items )
	{
		$result = $db->query( "
			SELECT p.ID, t.term_id AS attrid, tr.term_order AS pos
			FROM wp_posts p
			JOIN wp_term_relationships tr ON p.ID = tr.object_id
			JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			JOIN wp_terms t ON t.term_id = tt.term_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish' AND tt.taxonomy LIKE 'pa_%'
		" );

		foreach( $result->iterateAssociative() as $row )
		{
			if( $item = $items->get( $row['ID'] ) )
			{
				$listItem = $item->getListItem( 'attribute', 'config', $row['attrid'] ) ?? $manager->createListItem();
				$item->addListItem( 'attribute', $listItem->setType( 'config' )->setRefId( $row['attrid'] )->setPosition( $row['pos'] ) );
			}
		}
	}


	protected function brands( \Aimeos\Upscheme\Schema\DB $db, \Aimeos\MShop\Common\Manager\Iface $manager, \Aimeos\Map $items )
	{
		$result = $db->query( "
			SELECT p.ID, t.term_id AS brandid, tr.term_order AS pos
			FROM wp_posts p
			JOIN wp_term_relationships tr ON p.ID = tr.object_id
			JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			JOIN wp_terms t ON t.term_id = tt.term_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish' AND tt.taxonomy='brand'
		" );

		foreach( $result->iterateAssociative() as $row )
		{
			if( $item = $items->get( $row['ID'] ) )
			{
				$listItem = $item->getListItem( 'supplier', 'default', $row['brandid'] ) ?? $manager->createListItem();
				$item->addListItem( 'supplier', $listItem->setRefId( $row['brandid'] )->setPosition( $row['pos'] ) );
			}
		}
	}


	protected function categories( \Aimeos\Upscheme\Schema\DB $db, \Aimeos\MShop\Common\Manager\Iface $manager, \Aimeos\Map $items )
	{
		$result = $db->query( "
			SELECT p.ID, t.term_id AS catid, tr.term_order AS pos
			FROM wp_posts p
			JOIN wp_term_relationships tr ON p.ID = tr.object_id
			JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			JOIN wp_terms t ON t.term_id = tt.term_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish' AND tt.taxonomy='product_cat'
		" );

		foreach( $result->iterateAssociative() as $row )
		{
			if( $item = $items->get( $row['ID'] ) )
			{
				$listItem = $item->getListItem( 'catalog', 'default', $row['catid'] ) ?? $manager->createListItem();
				$item->addListItem( 'catalog', $listItem->setRefId( $row['catid'] )->setPosition( $row['pos'] ) );
			}
		}
	}


	protected function images( \Aimeos\Upscheme\Schema\DB $db, \Aimeos\MShop\Common\Manager\Iface $manager, \Aimeos\Map $items )
	{
		$mediaManager = \Aimeos\MShop::create( $this->context(), 'media' );

		$result = $db->query( "
			SELECT p.ID, am.meta_value AS image
			FROM wp_posts p
			JOIN wp_postmeta pm ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id'
			JOIN wp_postmeta am ON am.post_id = pm.meta_value AND am.meta_key = '_wp_attached_file'
			WHERE p.post_type = 'product' AND p.post_status = 'publish'
		" );

		foreach( $result->iterateAssociative() as $row )
		{
			if( $item = $items->get( $row['ID'] ) )
			{
				$listItem = $item->getListItems( 'text', 'default', 'long' )->first() ?? $manager->createListItem();
				$refItem = $listItem->getRefItem() ?: $mediaManager->create();
				$item->addListItem( 'media', $listItem, $refItem->setUrl( $row['image'] ) );
			}
		}
	}


	protected function prices( \Aimeos\Upscheme\Schema\DB $db, \Aimeos\MShop\Common\Manager\Iface $manager, \Aimeos\Map $items )
	{
		$priceManager = \Aimeos\MShop::create( $this->context(), 'price' );
		$currencyId = $this->context()->locale()->getCurrencyId();

		$result = $db->query( "
			SELECT post_id, meta_value
			FROM wp_postmeta
			WHERE meta_key = '_price'
		" );

		foreach( $result->iterateAssociative() as $row )
		{
			if( $item = $items->get( $row['post_id'] ) )
			{
				$listItem = $item->getListItems( 'price', 'default', 'default' )->first() ?? $manager->createListItem();

				$refItem = $listItem->getRefItem() ?: $priceManager->create();
				$refItem->setValue( $row['meta_value'] )->setCurrencyId( $currencyId );

				$item->addListItem( 'price', $listItem, $refItem );
			}
		}
	}


	protected function properties( \Aimeos\Upscheme\Schema\DB $db, \Aimeos\MShop\Common\Manager\Iface $manager, \Aimeos\Map $items )
	{
		$result = $db->query( "
			SELECT post_id, meta_key, meta_value
			FROM wp_postmeta
			WHERE meta_key in ('_weight', '_width', '_length', '_height')
		" );

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


	protected function suggests( \Aimeos\Upscheme\Schema\DB $db, \Aimeos\MShop\Common\Manager\Iface $manager, \Aimeos\Map $items )
	{
		$result = $db->query( "
			SELECT post_id, meta_value
			FROM wp_postmeta
			WHERE meta_key = '_crosssell_ids'
		" );

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
}
