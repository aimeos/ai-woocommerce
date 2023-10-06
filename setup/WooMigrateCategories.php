<?php

/**
 * @license LGPLv3, https://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2023
 */


namespace Aimeos\Upscheme\Task;


class WooMigrateCategories extends Base
{
	public function after() : array
	{
		return ['MShopSetLocale'];
	}


	public function up()
	{
		$this->info( 'Migrate WooCommerce categories', 'vv' );

		$manager = \Aimeos\MShop::create( $this->context(), 'catalog' );
		$cats = $manager->getTree( null, ['text'] )->toList();
		$root = $manager->find( 'home' );

		$db = $this->db( 'db-woocommerce' );
		$db2 = $this->db( 'db-catalog' );

		$result = $db->query( '
			SELECT
				t.term_id, tt.parent, t.name, t.slug, tt.description,
				st.meta_value AS metatitle,
				sd.meta_value AS metadesc
			FROM wp_terms t
			JOIN wp_term_taxonomy tt ON t.term_id=tt.term_id
			LEFT JOIN wp_termmeta st ON st.term_id=t.term_id AND st.meta_key=\'_seopress_titles_title\'
			LEFT JOIN wp_termmeta sd ON sd.term_id=t.term_id AND sd.meta_key=\'_seopress_titles_desc\'
			WHERE taxonomy=\'product_cat\'
			ORDER BY tt.parent, t.name
		' );

		foreach( $result->iterateKeyValue() as $key => $row )
		{
			$item = $cats[$row['term_id']] ?? $manager->create();
			$item->setLabel( $row['name'] )->setUrl( $row['slug'] );

			$listItem = $item->getListItems( 'text', 'default', 'long' )->first() ?? $manager->createListItem();
			$item->addListItem( 'text', $listItem->setContent( $row['description'] ) );

			$listItem = $item->getListItems( 'text', 'default', 'meta-title' )->first() ?? $manager->createListItem();
			$item->addListItem( 'text', $listItem->setContent( $row['metatitle'] ) );

			$listItem = $item->getListItems( 'text', 'default', 'meta-description' )->first() ?? $manager->createListItem();
			$item->addListItem( 'text', $listItem->setContent( $row['metadesc'] ) );

			$parent = $cats[$row['parent']] ?? null;
			$item->getId() ? $manager->save( $item ) : $manager->insert( $item, $parent?->getId() );

			$db2->update( 'mshop_catalog', ['id' => $row['term_id']], ['id' => $item->getId()] );

			$cats[$item->getId()] = $item;
		}
	}
}
