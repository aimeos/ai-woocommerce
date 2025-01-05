<?php

/**
 * @license LGPLv3, https://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2023-2025
 */


namespace Aimeos\Upscheme\Task;


class WooMigrateAttributes extends Base
{
	public function after() : array
	{
		return ['Attribute', 'MShopSetLocale', 'WooMigrateAttributeTypes'];
	}


	public function up()
	{
		$db = $this->db( 'db-woocommerce' );

		if( !$db->hasTable( 'wp_terms' ) ) {
			return;
		}

		$this->info( 'Migrate WooCommerce attributes', 'vv' );

		$context = $this->context();
		$mediaManager = \Aimeos\MShop::create( $context, 'media' );
		$textManager = \Aimeos\MShop::create( $context, 'text' );
		$manager = \Aimeos\MShop::create( $context, 'attribute' );
		$items = $manager->search( $manager->filter()->slice( 0, 0x7fffffff ), ['media', 'text'] );

		$result = $db->query( "
			SELECT
				t.term_id,
				t.name,
				t.slug,
				tt.taxonomy,
				tt.description,
				pi.guid AS imgurl,
				pi.post_title AS imglabel,
				pi.post_mime_type AS mimetype
			FROM wp_terms t
			JOIN wp_term_taxonomy tt ON t.term_id=tt.term_id AND tt.taxonomy LIKE 'pa_%'
			LEFT JOIN wp_termmeta tmi ON tmi.term_id=t.term_id AND tmi.meta_key='st-image-swatch'
			LEFT JOIN wp_posts pi ON pi.ID=tmi.meta_value
			ORDER BY tt.taxonomy, t.name
		" );

		$langId = $this->context()->locale()->getLanguageId();
		$rows = [];
		$type = '';
		$pos = 0;

		foreach( $result->iterateAssociative() as $row )
		{
			if( $row['taxonomy'] !== $type )
			{
				$type = $row['taxonomy'];
				$pos = 0;
			}

			$item = $items->get( $row['term_id'] ) ?: $manager->create();
			$item->setCode( $row['slug'] )
				->setLabel( $row['name'] )
				->setDomain( 'product' )
				->setPosition( $pos++ )
				->setType( substr( $row['taxonomy'], 3 ) );

			$items[$row['term_id']] = $item;
			$rows[] = $row;
		}

		$manager->begin();
		$manager->save( $items );
		$manager->commit();

		$this->db( 'db-attribute' )->transaction( function( $db ) use ( $items ) {

			foreach( $items as $id => $item )
			{
				if( $id != $item->getId() )
				{
					$db->update( 'mshop_attribute', ['id' => $id], ['id' => $item->getId()] );
					$item->setId( $id );
				}
			}
		} );

		foreach( $rows as $row )
		{
			$item = $items->get( $row['term_id'] );

			if( $text = $row['description'] ?? null )
			{
				$listItem = $item->getListItems( 'text', 'default', 'long' )->first() ?? $manager->createListItem();
				$refItem = $listItem->getRefItem() ?: $textManager->create();
				$refItem->setType( 'long' )->setLanguageId( $langId )->setContent( $text );
				$item->addListItem( 'text', $listItem, $refItem->setLabel( mb_substr( strip_tags( $text ), 0, 60 ) ) );
			}

			if( $image = $row['imgurl'] ?? null )
			{
				$listItem = $item->getListItems( 'media', 'default', 'icon' )->first() ?? $manager->createListItem();
				$refItem = $listItem->getRefItem() ?: $mediaManager->create();
				$refItem->setType( 'icon' )->setUrl( $image )->setMimetype( $row['mimetype'] ?? '' );
				$item->addListItem( 'media', $listItem, $refItem->setLabel( $row['imglabel'] ?? $image ) );
			}
		}

		$manager->begin();
		$manager->save( $items );
		$manager->commit();
	}
}
