<?php

/**
 * @license LGPLv3, https://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2023
 */


namespace Aimeos\Upscheme\Task;


class WooMigrateBrands extends Base
{
	public function after() : array
	{
		return ['Supplier', 'MShopSetLocale'];
	}


	public function up()
	{
		$db = $this->db( 'db-woocommerce' );

		if( !$db->hasTable( 'wp_terms' ) ) {
			return;
		}

		$this->info( 'Migrate WooCommerce brands', 'vv' );

		$context = $this->context();
		$mediaManager = \Aimeos\MShop::create( $context, 'media' );
		$textManager = \Aimeos\MShop::create( $context, 'text' );
		$manager = \Aimeos\MShop::create( $context, 'supplier' );
		$brands = $manager->search( $manager->filter()->slice( 0, 0x7fffffff ), ['text'] );

		$langId = $context->locale()->getLanguageId();

		$db2 = $this->db( 'db-supplier' );

		$result = $db->query( "
			SELECT
				t.term_id,
				t.name,
				t.slug,
				tt.description,
				st.meta_value AS metatitle,
				sd.meta_value AS metadesc,
				pi.guid AS imgurl,
				pi.post_title AS imglabel,
				pi.post_mime_type AS mimetype
			FROM wp_terms t
			JOIN wp_term_taxonomy tt ON t.term_id=tt.term_id
			LEFT JOIN wp_termmeta st ON st.term_id=t.term_id AND st.meta_key='_seopress_titles_title'
			LEFT JOIN wp_termmeta sd ON sd.term_id=t.term_id AND sd.meta_key='_seopress_titles_desc'
			LEFT JOIN wp_termmeta si ON si.term_id=t.term_id AND si.meta_key='thumbnail_id'
			LEFT JOIN wp_posts pi ON pi.ID=si.meta_value
			WHERE taxonomy='brand'
			ORDER BY t.name
		" );

		foreach( $result->iterateAssociative() as $row )
		{
			$item = $brands[$row['term_id']] ?? $manager->create();
			$item->setCode( $row['slug'] )->setLabel( $row['name'] );

			if( !$item->getId() )
			{
				$item = $manager->save( $item );

				$db2->update( 'mshop_supplier', ['id' => $row['term_id']], ['id' => $item->getId()] );
				$item->setId( $row['term_id'] );
			}

			if( $text = $row['description'] ?? null )
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

			if( $image = $row['imgurl'] ?? null )
			{
				$listItem = $item->getListItems( 'media', 'default', 'default' )->first() ?? $manager->createListItem();
				$refItem = $listItem->getRefItem() ?: $mediaManager->create();
				$refItem->setType( 'default' )->setUrl( $image )->setMimetype( $row['mimetype'] ?? '' );
				$item->addListItem( 'media', $listItem, $refItem->setLabel( $row['imglabel'] ?? $image ) );
			}

			$brands[$item->getId()] = $manager->save( $item );
		}
	}
}
