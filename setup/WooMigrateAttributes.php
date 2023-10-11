<?php

/**
 * @license LGPLv3, https://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2023
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

		$textManager = \Aimeos\MShop::create( $this->context(), 'text' );
		$manager = \Aimeos\MShop::create( $this->context(), 'attribute' );
		$items = $manager->search( $manager->filter()->slice( 0, 0x7fffffff ), ['text'] );

		$langId = $this->context()->locale()->getLanguageId();

		$db2 = $this->db( 'db-attribute' );

		$result = $db->query( "
			SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.description
			FROM wp_terms t
			JOIN wp_term_taxonomy tt ON t.term_id=tt.term_id
			WHERE taxonomy LIKE 'pa_%'
			ORDER BY tt.taxonomy, t.name
		" );

		$pos = 0;
		$type = '';

		foreach( $result->iterateAssociative() as $row )
		{
			if( $row['taxonomy'] !== $type )
			{
				$type = $row['taxonomy'];
				$pos = 0;
			}

			$item = $items[$row['term_id']] ?? $manager->create();
			$item->setCode( $row['slug'] )
				->setLabel( $row['name'] )
				->setDomain( 'product' )
				->setPosition( $pos++ )
				->setType( substr( $row['taxonomy'], 3 ) );

			if( !$item->getId() )
			{
				$item = $manager->save( $item );

				$db2->update( 'mshop_attribute', ['id' => $row['term_id']], ['id' => $item->getId()] );
				$item->setId( $row['term_id'] );
			}

			if( $text = $row['description'] ?? null )
			{
				$listItem = $item->getListItems( 'text', 'default', 'long' )->first() ?? $manager->createListItem();
				$refItem = $listItem->getRefItem() ?: $textManager->create();
				$refItem->setType( 'long' )->setLanguageId( $langId )->setContent( $text );
				$item->addListItem( 'text', $listItem, $refItem->setLabel( mb_substr( strip_tags( $text ), 0, 60 ) ) );
			}

			$manager->save( $item );
		}
	}
}
