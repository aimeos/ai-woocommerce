<?php

/**
 * @license LGPLv3, https://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2023-2024
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
				pm.stock_status,
				st.meta_value AS metatitle,
				sd.meta_value AS metadesc
			FROM wp_posts p
			JOIN wp_wc_product_meta_lookup pm ON p.ID = pm.product_id
			JOIN wp_term_relationships tr ON p.ID = tr.object_id
			JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			JOIN wp_terms t ON t.term_id = tt.term_id
			LEFT JOIN wp_postmeta st ON st.post_id=p.ID AND st.meta_key='_seopress_titles_title'
			LEFT JOIN wp_postmeta sd ON sd.post_id=p.ID AND sd.meta_key='_seopress_titles_desc'
			WHERE p.post_type = 'product' AND p.post_status = 'publish' AND tt.taxonomy='product_type'
		" );

		$items = $this->products( $result, $items );

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

		$items = $this->products( $result, $items );

		$this->properties( $items );
		$this->deliveries( $items );
		$this->categories( $items );
		$this->attributes( $items );
		$this->attributeVariants( $items );
		$this->brands( $items );
		$this->images( $items );
		$this->prices( $items );
		$this->suggests( $items );

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
		$manager = \Aimeos\MShop::create( $this->context(), 'product' );
		$attrManager = \Aimeos\MShop::create( $this->context(), 'attribute' );
		$attrs = $attrManager->search( $attrManager->filter()->slice( 0, 0x7fffffff ) );

		$result = $this->db( 'db-woocommerce' )->query( "
			SELECT
				p.ID,
				tr.term_taxonomy_id AS attrid
			FROM wp_posts p
			JOIN wp_term_relationships AS tr on p.ID = tr.object_id
			JOIN wp_term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_id
			WHERE
				p.post_type = 'product' AND
				p.post_status = 'publish' AND
				tt.taxonomy LIKE 'pa_%'
		" );

		foreach( $result->iterateAssociative() as $row )
		{
			if( ( $item = $items->get( $row['ID'] ) ) !== null && ( $attrItem = $attrs->get( $row['attrid'] ) ) !== null )
			{
				$listItem = $item->getListItem( 'attribute', 'default', $attrItem->getId() ) ?? $manager->createListItem();
				$item->addListItem( 'attribute', $listItem->setType( 'default' ), $attrItem );
			}
		}
	}


	protected function attributeVariants( \Aimeos\Map $items )
	{
		$context = $this->context();
		$manager = \Aimeos\MShop::create( $context, 'product' );
		$attrManager = \Aimeos\MShop::create( $context, 'attribute' );
		$typeManager = \Aimeos\MShop::create( $context, 'attribute/type' );

		$attrMap = $attrManager->search( $attrManager->filter()->slice( 0, 0x7fffffff ) )->rekey( function( $item ) {
			return $item->getType() . '/' . $item->getCode();
		} );

		$attrTypes = $typeManager->search( $typeManager->filter()->slice( 0, 0x7fffffff ) )->col( null, 'attribute.type.code' );

		$result = $this->db( 'db-woocommerce' )->query( "
			SELECT
				p.ID,
				pm.meta_key AS attrtype,
				pm.meta_value AS attrname
			FROM wp_posts p
			JOIN wp_postmeta pm ON p.ID = pm.post_id AND pm.meta_key LIKE 'attribute_%'
			WHERE
				p.post_type = 'product_variation' AND
				p.post_status = 'publish'
		" );

		$attrManager->begin();

		foreach( $result->iterateAssociative() as $row )
		{
			$name = $row['attrname'];
			$code = \Aimeos\Base\Str::slug( $name );
			$ftype = substr( $row['attrtype'], 10 );
			$type = substr( $ftype, substr_compare( $ftype, 'pa_', 0, 3 ) === 0 ? 3 : 0 );
			$key = $type . '/' . $code;

			if( $item = $items->get( $row['ID'] ) )
			{
				if( $attrTypes->get( $type ) === null ) {
					$attrTypes[$type] = $typeManager->create()->setDomain( 'product' )->setCode( $type )->setLabel( $type );
				}

				if( ( $attrItem = $attrMap->get( $key ) ) === null )
				{
					$attrItem = $attrManager->create()->setDomain( 'product' )->setType( $type )->setLabel( $name );
					$attrMap[$key] = $attrManager->save( $attrItem->setCode( $code ) );
				}

				$listItem = $item->getListItem( 'attribute', 'variant', $attrItem->getId() ) ?? $manager->createListItem();
				$item->addListItem( 'attribute', $listItem->setType( 'variant' ), $attrItem );
			}
		}

		$attrManager->commit();

		$typeManager->begin();
		$typeManager->save( $attrTypes );
		$typeManager->commit();
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


	protected function deliveries( \Aimeos\Map $items )
	{
		$typeManager = \Aimeos\MShop::create( $this->context(), 'attribute/type' );

		try {
			$typeItem = $typeManager->find( 'delivery', [], 'product' );
		} catch( \Exception $e ) {
			$typeItem = $typeManager->save( $typeManager->create()->setDomain( 'product' )->setCode( 'delivery' )->setLabel( 'Delivery' ) );
		}

		$attrManager = \Aimeos\MShop::create( $this->context(), 'attribute' );
		$attrItems = $attrManager->search( $attrManager->filter()->add( 'attribute.type', '==', 'delivery' ) )->col( null, 'attribute.code' );

		$result = $this->db( 'db-woocommerce' )->query( "
			SELECT
				p.ID,
				t.name,
				t.slug
			FROM wp_posts AS p
			JOIN wp_term_relationships AS tr ON p.ID = tr.object_id
			JOIN wp_terms AS t ON t.term_id = tr.term_taxonomy_id
			JOIN wp_term_taxonomy AS tt ON tt.term_id = t.term_id
			WHERE tt.taxonomy = 'product_shipping_class'
		" );

		$manager = \Aimeos\MShop::create( $this->context(), 'product' );

		foreach( $result->iterateAssociative() as $row )
		{
			if( ( $item = $items->get( $row['ID'] ) ) && ( $code = $row['slug'] ) )
			{
				if( ( $attrItem = $attrItems->get( $code ) ) === null )
				{
					$attrItem = $attrManager->create()->setDomain( 'product' )->setType( 'delivery' )->setLabel( $row['name'] );
					$attrItems[$code] = $attrManager->save( $attrItem->setCode( $code ) );
				}

				$listItem = $item->getListItem( 'attribute', 'hidden', $attrItem->getId() ) ?? $manager->createListItem();
				$item->addListItem( 'attribute', $listItem->setType( 'hidden' )->setRefId( $attrItem->getId() ) );
			}
		}
	}


	protected function images( \Aimeos\Map $items )
	{
		$mediaManager = \Aimeos\MShop::create( $this->context(), 'media' );

		$db2 = $this->db( 'db-woocommerce', true );
		$result = $this->db( 'db-woocommerce' )->query( "
			SELECT
				p.ID,
				p.post_title,
				main_img.meta_value AS image,
				gallery_pm.meta_value AS image_ids
			FROM  wp_posts p
			JOIN wp_postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
			JOIN wp_postmeta main_img ON main_img.post_id = pm.meta_value AND main_img.meta_key = '_wp_attached_file'
			LEFT JOIN wp_postmeta gallery_pm ON p.ID = gallery_pm.post_id AND gallery_pm.meta_key = '_product_image_gallery'
			WHERE
				p.post_type = 'product' AND
				p.post_status = 'publish'
		" );

		$manager = \Aimeos\MShop::create( $this->context(), 'product' );

		foreach( $result->iterateAssociative() as $row )
		{
			if( $item = $items->get( $row['ID'] ) )
			{
				$listItems = $item->getListItems( 'media', 'default', 'default' )->reverse();
				$images = [$row['image']];
				$pos = 0;

				if( $row['image_ids'] )
				{
					$ids = array_filter( explode( ',', $row['image_ids'] ) );
					$images += $db2->query( "
						SELECT post_id, meta_value FROM wp_postmeta
						WHERE post_id IN (" . join( ',', $ids ) . ") AND meta_key = '_wp_attached_file'
					" )->fetchAllKeyValue();
				}

				foreach( $images as $image )
				{
					$listItem = $listItems->pop() ?? $manager->createListItem();
					$refItem = $listItem->getRefItem() ?: $mediaManager->create();
					$refItem->setUrl( $image )->setLabel( $row['post_title'] )->setMimetype( $this->mime( $image ) );
					$item->addListItem( 'media', $listItem->setPosition( $pos++ ), $refItem );
				}
			}
		}

		$db2->close();
	}


	protected function mime( string $name ) : string
	{
		return match( pathinfo( $name, PATHINFO_EXTENSION ) ) {
			'webp' => 'image/webp',
			'jpeg' => 'image/jpeg',
			'jpg' => 'image/jpeg',
			'png' => 'image/png',
			'gif' => 'image/gif',
		};
	}


	protected function prices( \Aimeos\Map $items )
	{
		$context = $this->context();
		$db = $this->db( 'db-woocommerce' );
		$currencyId = $context->locale()->getCurrencyId();

		$manager = \Aimeos\MShop::create( $context, 'product' );
		$priceManager = \Aimeos\MShop::create( $context, 'price' );

		$taxrate = $db->query( "SELECT tax_rate FROM wp_woocommerce_tax_rates LIMIT 1" )->fetchOne();
		$priceItem = $priceManager->create()->setDomain( 'product' )->setType( 'default' )
			->setCurrencyId( $currencyId )->setTaxrate( $taxrate );

		$result = $db->query( "
			SELECT
				p.ID,
				p.post_parent AS selectionid,
				pm.meta_value AS price,
				pms.meta_value AS saleprice
			FROM wp_posts p
			JOIN wp_postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_regular_price'
			LEFT JOIN wp_postmeta pms ON p.ID = pms.post_id AND pms.meta_key = '_sale_price'
			ORDER BY p.ID
		" );

		foreach( $result->iterateAssociative() as $row )
		{
			if( $item = $items->get( $row['ID'] ) )
			{
				$listItems = $item->getListItems( 'price', 'default', 'default', false )->reverse();

				$listItem = $listItems->pop() ?? $manager->createListItem();
				$refItem = $listItem->getRefItem() ?: clone $priceItem;
				$refItem->setValue( $row['price'] );
				$item->addListItem( 'price', $listItem, $refItem );

				if( $row['saleprice'] > 0 )
				{
					$listItem = $listItems->pop() ?? $manager->createListItem();
					$refItem = $listItem->getRefItem() ?: clone $priceItem;
					$refItem->setValue( $row['saleprice'] )->setRebate( $row['price'] - $row['saleprice'] );
					$item->addListItem( 'price', $listItem, $refItem );
				}

				$item->deleteListItems( $listItems );
			}

			if( $item = $items->get( $row['selectionid'] ) )
			{
				$value = $row['saleprice'] > 0 ? $row['saleprice'] : $row['price'];
				$rebate = $row['saleprice'] > 0 ? (float) $row['price'] - (float) $row['saleprice'] : '0.00';

				if( ( $listItem = $item->getListItems( 'price', 'default', 'default', false )->first() ) === null ) {
					$item->addListItem( 'price', $listItem = $manager->createListItem(), $refItem = clone $priceItem );
				} else {
					$refItem = $listItem->getRefItem();
				}

				if( $refItem->getValue() == 0 || $refItem->getValue() > $value ) {
					$refItem->setValue( $value )->setRebate( $rebate );
				}
			}
		}
	}


	public function products( \Doctrine\DBAL\Result $result, \Aimeos\Map $items ) : \Aimeos\Map
	{
		$stockManager = \Aimeos\MShop::create( $this->context(), 'stock' );
		$textManager = \Aimeos\MShop::create( $this->context(), 'text' );
		$manager = \Aimeos\MShop::create( $this->context(), 'product' );

		$langId = $this->context()->locale()->getLanguageId();
		$rows = [];

		foreach( $result->iterateAssociative() as $row )
		{
			$code = str_replace( ' ', '-', $row['sku'] );

			if( strlen( $code ) > 64 ) {
				$code = substr( $code, 0, 60 ) . '_' . substr( md5( microtime( true) . rand() ), -3 );
			}

			$item = $items->get( $row['ID'] ) ?: $manager->create();
			$item->setCode( $code ?: 'woo-' . $row['ID'] )
				->setUrl( $row['post_name'] )
				->setLabel( $row['post_title'] )
				->setType( $this->type( $row['type'] ?? '' ) )
				->setTimeCreated( $row['post_date_gmt'] );

			$items[$row['ID']] = $item;
			$rows[] = $row;
		}

		$manager->begin();
		$manager->save( $items );
		$manager->commit();

		$this->db( 'db-product' )->transaction( function( $db ) use ( $items ) {

			foreach( $items as $id => $item )
			{
				if( $id != $item->getId() )
				{
					$db->update( 'mshop_product', ['id' => $id], ['id' => $item->getId()] );
					$item->setId( $id );
				}
			}
		} );


		foreach( $rows as $row )
		{
			$item = $items->get( $row['ID'] );

			if( $short = $row['post_excerpt'] ?? null )
			{
				$listItem = $item->getListItems( 'text', 'default', 'short' )->first() ?? $manager->createListItem();
				$refItem = $listItem->getRefItem() ?: $textManager->create();
				$refItem->setType( 'short' )->setLanguageId( $langId )->setContent( $short );
				$item->addListItem( 'text', $listItem, $refItem->setLabel( mb_substr( strip_tags( $short ), 0, 60 ) ) );
			}

			if( ( $text = $row['post_content'] ?? null ) && $text !== $short )
			{
				$listItem = $item->getListItems( 'text', 'default', 'long' )->first() ?? $manager->createListItem();
				$refItem = $listItem->getRefItem() ?: $textManager->create();
				$refItem->setType( 'long' )->setLanguageId( $langId )->setContent( $text );
				$item->addListItem( 'text', $listItem, $refItem->setLabel( mb_substr( strip_tags( $text ), 0, 60 ) ) );
			}

			if( $text = $row['metatitle'] ?? null )
			{
				$listItem = $item->getListItems( 'text', 'default', 'meta-title' )->first() ?? $manager->createListItem();
				$refItem = $listItem->getRefItem() ?: $textManager->create();
				$refItem->setType( 'meta-title' )->setLanguageId( $langId )->setContent( $text );
				$item->addListItem( 'text', $listItem, $refItem->setLabel( mb_substr( strip_tags( $text ), 0, 60 ) ) );
			}

			if( $text = $row['metadesc'] ?? null )
			{
				$listItem = $item->getListItems( 'text', 'default', 'meta-description' )->first() ?? $manager->createListItem();
				$refItem = $listItem->getRefItem() ?: $textManager->create();
				$refItem->setType( 'meta-description' )->setLanguageId( $langId )->setContent( $text );
				$item->addListItem( 'text', $listItem, $refItem->setLabel( mb_substr( strip_tags( $text ), 0, 60 ) ) );
			}

			if( $row['stock_status'] ?? null )
			{
				$stockItem = $item->getStockItems( 'default' )->first() ?? $stockManager->create();
				$stockManager->save( $stockItem->setProductId( $row['ID'] )->setStockLevel( $row['stock_quantity'] ) );
			}

			if( $parent = $items[$row['post_parent'] ?? null] ?? null )
			{
				$listItem = $parent->getListItem( 'product', 'default', $row['ID'] ) ?? $manager->createListItem();
				$parent->addListItem( 'product', $listItem->setRefId( $row['ID'] ) );
			}
		}

		return $items;
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
}
