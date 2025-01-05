<?php

/**
 * @license LGPLv3, https://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2023-2025
 */


namespace Aimeos\Upscheme\Task;


class WooMigrateExtraProductsOptions extends Base
{
	private $context;
	private $attribute;
	private $attrTypes;
	private $listItem;
	private $media;
	private $price;
	private $text;


	public function after() : array
	{
		return ['Catalog', 'Product', 'WooMigrateProducts', 'WooMigrateCategories'];
	}


	public function up()
	{
		$db = $this->db( 'db-woocommerce' );

		if( !$db->hasTable( 'wp_posts' ) ) {
			return;
		}

		$this->info( 'Migrate WooCommerce extra product options', 'vv' );

		$result = $db->query( "
			SELECT
				p.ID,
				t.term_id AS catid,
				pmt.meta_value AS template,
				pme.meta_value AS excludes
			FROM wp_posts p
			JOIN wp_term_relationships tr ON p.ID = tr.object_id
			JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			JOIN wp_terms t ON t.term_id = tt.term_id
			JOIN wp_postmeta pmt ON p.ID = pmt.post_id AND pmt.meta_key = 'tm_meta'
			LEFT JOIN wp_postmeta pme ON p.ID = pme.post_id AND pme.meta_key = 'tm_meta_product_exclude_ids'
			LEFT JOIN wp_postmeta pmd ON p.ID = pmd.post_id AND pmd.meta_key = 'tm_meta_disable_categories'
			WHERE
				p.post_status = 'publish' AND p.post_type = 'tm_global_cp' AND
				( pmd.meta_value != '1' OR pmd.meta_value IS NULL ) AND
				tt.taxonomy = 'product_cat'
			ORDER BY p.ID
		" );

		$map = $catids = [];
		foreach( $result->iterateAssociative() as $row )
		{
			$catids[$row['ID']][] = $row['catid'];
			$map[$row['ID']] = $row;
		}

		$this->update( $map, $catids );
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


	public function update( array $map, array $catids )
	{
		$pos = 0;

		foreach( $map as $tid => $row )
		{
			if( ( $content = unserialize( $row['template'] ?? '' ) ) === false ) {
				error_log( sprintf( 'Template "%1$s" can not be unserialized', $tid ) );
				continue;
			}

			if( ( $section = $content['tmfbuilder'] ?? null ) === null ) {
				continue;
			}

			if( in_array( 'radiobuttons', $section['element_type'] )
				&& !empty( $section['multiple_radiobuttons_options_enabled'] )
			) {
				$typeItem = $this->attributeType( $tid, $section, $content['priority'] ?? 0 );
				$attrIds = $this->attributesItems( $tid, $section, $typeItem->getCode() );

				$excluded = unserialize( $row['excludes'] ?? '' ) ?: [];
				$this->assign( $catids[$tid] ?? [], $attrIds, 'attribute', 'config', $excluded, $pos++ );
			}
		}
	}


	protected function addProducts( array $section, int $idx, \Aimeos\MShop\Attribute\Item\Iface $item )
	{
		if( $section['product_enabled'][$idx] ?? null )
		{
			if( ( $section['product_mode'][$idx] ?? '' ) === 'categories' )
			{
				$catIds = (array) $section['product_categoryids'][$idx] ?? [];
				$sort = $section['product_orderby'][$idx] ?? 'ID';
				$dir = $section['product_order'][$idx] ?? 'asc';

				$prodIds = $this->catproducts( $catIds, $sort, $dir );
			}
			elseif( in_array( $section['product_mode'][$idx] ?? '', ['products', 'product'] ) )
			{
				$prodIds = (array) $section['product_productids'][$idx] ?? [];
			}
			else
			{
				error_log( sprintf( 'Unknown product mode %1$s', $section['product_mode'][$idx] ?? '' ) );
				return;
			}

			$listItems = $item->getListItems( 'product', 'default' )->reverse();

			foreach( $prodIds as $pos => $prodId )
			{
				$listItem = $item->getListItem( 'product', 'default', $prodId ) ?: $this->listItem();
				$item->addListItem( 'product', $listItem->setRefId( $prodId )->setPosition( $pos ) );
				$listItems->remove( $listItem->getId() );
			}

			$item->deleteListItems( $listItems );
		}
	}


	protected function addRadioButtons( string $tid, array $section, string $typeCode , int $idx, \Aimeos\Map $attrItems, int $elIdx ) : array
	{
		$assign = [];

		if( !( $section['radiobuttons_enabled'][$idx] ?? null ) ) {
			return $assign;
		}

		foreach( $section['multiple_radiobuttons_options_enabled'][$idx] as $key => $status )
		{
			if( !$status ) {
				continue;
			}

			$price = $section['multiple_radiobuttons_options_price'][$idx][$key] ?? 0;
			$name = $section['multiple_radiobuttons_options_title'][$idx][$key] ?? '';
			$image = $section['multiple_radiobuttons_options_image'][$idx][$key] ?? null;
			$cimage = $section['multiple_radiobuttons_options_imagec'][$idx][$key] ?? null;
			$lgimage = $section['multiple_radiobuttons_options_imagel'][$idx][$key] ?? null;
			$desc = $section['multiple_radiobuttons_options_description'][$idx][$key] ?? '';
			$code = \Aimeos\Base\Str::slug( $section['multiple_radiobuttons_options_value'][$idx][$key] ?? '' );

			if( !$code ) {
				error_log( 'No value for "multiple_radiobuttons_options_value" in template ' . $tid );
				continue;
			}

			$code .= '_' . $tid . '_' . $idx . '_' . $key;

			if( $idx !== 0 && $price > 0 )
			{
				$this->createProduct( $typeCode . '_' . $code, $name, $desc, $price, [$lgimage, $image, $cimage] );
				continue;
			}

			$item = $attrItems[$code] ?? $this->attribute();
			$item->setCode( $code )->setLabel( $name ?: $code )->setType( $typeCode )->setPosition( $elIdx * 100 + $idx * 10 + $key );

			$mediaListItems = $item->getListItems( 'media', 'default', 'icon' )->reverse();
			foreach( array_unique( array_filter( [$image, $cimage] ) ) as $pos => $imgurl )
			{
				$listItem = $mediaListItems->pop() ?: $this->listItem();
				$refItem = $listItem->getRefItem() ?: $this->media();
				$refItem->setType( 'icon' )->setUrl( $imgurl )->setLabel( $name )->setMimetype( $this->mime( $imgurl ) );
				$item->addListItem( 'media', $listItem, $refItem );
			}
			$item->deleteListItems( $mediaListItems );

			if( $lgimage )
			{
				$listItem = $item->getListItems( 'media', 'default', 'default' )->first() ?: $this->listItem();
				$refItem = $listItem->getRefItem() ?: $this->media();
				$refItem->setType( 'default' )->setUrl( $lgimage )->setLabel( $name )->setMimetype( $this->mime( $lgimage ) );
				$item->addListItem( 'media', $listItem, $refItem );
			}

			if( $desc )
			{
				$listItem = $item->getListItems( 'text', 'default', 'long' )->first() ?: $this->listItem();
				$refItem = $listItem->getRefItem() ?: $this->text();
				$refItem->setType( 'long' )->setContent( $desc )->setLabel( mb_strcut( $desc, 0, 100 ) );
				$item->addListItem( 'text', $listItem, $refItem );
			}

			if( $price && preg_match( '/^[0-9]+\.?[0-9]*$/', $price ) === 1 )
			{
				$listItem = $item->getListItems( 'price', 'default', 'default' )->first() ?: $this->listItem();
				$refItem = $listItem->getRefItem() ?: $this->price();
				$refItem->setType( 'default' )->setValue( $price );
				$item->addListItem( 'price', $listItem, $refItem );
			}

			$attrItems[$code] = $item;
			$assign[$key][] = $item;
		}

		return $assign;
	}


	protected function addSelectBox( string $tid, array $section, string $typeCode, int $idx, \Aimeos\Map $attrItems, int $elIdx ) : array
	{
		$assign = [];

		if( !( $section['selectbox_enabled'][$idx] ?? null ) ) {
			return $assign;
		}

		foreach( $section['multiple_selectbox_options_value'][$idx] as $key => $value )
		{
			$price = $section['multiple_selectbox_options_price'][$idx][$key] ?? 0;
			$name = $section['multiple_selectbox_options_title'][$idx][$key] ?? '';
			$image = $section['multiple_selectbox_options_image'][$idx][$key] ?? null;
			$cimage = $section['multiple_selectbox_options_imagec'][$idx][$key] ?? null;
			$lgimage = $section['multiple_selectbox_options_imagel'][$idx][$key] ?? null;
			$code = \Aimeos\Base\Str::slug( $value );

			if( !$code ) {
				error_log( 'No value for "multiple_selectbox_options_value" in template ' . $tid );
				continue;
			}

			$code .= '_' . $tid . '_' . $idx . '_' . $key;
			$item = $attrItems[$code] ?? $this->attribute();
			$item->setCode( $code )->setLabel( $name ?: $code )->setType( $typeCode )->setPosition( $elIdx * 100 + $idx * 10 + $key );

			$mediaListItems = $item->getListItems( 'media', 'default', 'icon' )->reverse();

			foreach( array_filter( [$image, $cimage] ) as $pos => $imgurl )
			{
				$listItem = $mediaListItems->pop() ?: $this->listItem();
				$refItem = $listItem->getRefItem() ?: $this->media();
				$refItem->setType( 'icon' )->setUrl( $imgurl )->setLabel( $name )->setMimetype( $this->mime( $imgurl ) );
				$item->addListItem( 'media', $listItem, $refItem );
			}

			if( $lgimage )
			{
				$listItem = $item->getListItems( 'media', 'default', 'default' )->first() ?: $this->listItem();
				$refItem = $listItem->getRefItem() ?: $this->media();
				$refItem->setType( 'default' )->setUrl( $lgimage )->setLabel( $name )->setMimetype( $this->mime( $lgimage ) );
				$item->addListItem( 'media', $listItem, $refItem );
			}

			if( $price && preg_match( '/^[0-9]+\.?[0-9]*$/', $price ) === 1 )
			{
				$listItem = $item->getListItems( 'price', 'default', 'default' )->first() ?: $this->listItem();
				$refItem = $listItem->getRefItem() ?: $this->price();
				$refItem->setType( 'default' )->setValue( $price );
				$item->addListItem( 'price', $listItem, $refItem );
			}

			$attrItems[$code] = $item;
			$assign[$key][] = $item;
		}

		return $assign;
	}


	protected function assign( array $catids, array $refIds, string $domain, string $listType, array $excluded = [], $pos = 0 )
	{
		if( empty( $catids ) ) {
			return;
		}

		$context = $this->context();
		$manager = \Aimeos\MShop::create( $context, 'product' );

		$filter = $manager->filter();
		$filter->add( $filter->make( 'product:has', ['catalog', 'default', $catids] ), '!=', null );
		$cursor = $manager->cursor( $filter );

		while( $items = $manager->iterate( $cursor, [$domain] ) )
		{
			foreach( $items as $id => $item )
			{
				if( in_array( $id, $excluded ) ) {
					continue;
				}

				$idx = 0;
				foreach( $refIds as $refId )
				{
					$listItem = $item->getListItem( $domain, $listType, $refId ) ?? $manager->createListItem();
					$item->addListItem( $domain, $listItem->setType( $listType )->setRefId( $refId )->setPosition( $pos * 100 + $idx++ ) );
				}
			}

			$manager->begin();
			$manager->save( $items );
			$manager->commit();
		}
	}


	/**
	 * Returns an new attribute item
	 *
	 * @return \Aimeos\MShop\Attribute\Item\Iface New attribute Item
	 */
	protected function attribute() : \Aimeos\MShop\Attribute\Item\Iface
	{
		if( !isset( $this->attribute ) )
		{
			$manager = \Aimeos\MShop::create( $this->context(), 'attribute' );
			$this->attribute = $manager->create()->setDomain( 'product' );
		}

		return clone $this->attribute;
	}


	protected function attributesItems( string $tid, array $section, string $typeCode ) : array
	{
		$attrCodes = $attrs = [];
		$selectIdx = $radioIdx = $productIdx = 0;

		$attrManager = \Aimeos\MShop::create( $this->context(), 'attribute' );
		$filter = $attrManager->filter()->add( 'attribute.type', '==', $typeCode ) ->slice( 0, 0x7fffffff );
		$attrItems = $attrManager->search( $filter, ['media', 'price', 'product', 'text'] )->col( null, 'attribute.code' );

		foreach( $section['element_type'] ?? [] as $elIdx => $elType )
		{
			switch( $elType )
			{
				case 'selectbox':
					$attrs = $this->addSelectBox( $tid, $section, $typeCode, $selectIdx++, $attrItems, $elIdx );
					break;
				case 'radiobuttons':
					$attrs = $this->addRadioButtons( $tid, $section, $typeCode, $radioIdx++, $attrItems, $elIdx );
					break;
				case 'product':
					// this is not correct and must be fixed by hand because section/product logic can be very complicated
					foreach( $attrs as $key => $list ) {
						foreach( $list as $attr ) {
							$this->addProducts( $section, $productIdx++, $attr );
						}
					}
					$attrs = [];
				default:
					continue 2;
			}

			if( $selectIdx + $radioIdx === 1 ) { // only assign first selection level to products
				$attrCodes = array_merge( $attrCodes, map( $attrs )->flat( 1 )->col( 'attribute.code' )->all() );
			}
		}

		$attrManager->begin();
		$attrManager->save( $attrItems );
		$attrManager->commit();

		return $attrItems->only( array_unique( $attrCodes ) )->getId()->all();
	}


	protected function attributeType( string $tid, array $section, int $pos ) : \Aimeos\MShop\Common\Item\Type\Iface
	{
		$context = $this->context();
		$langId = $context->locale()->getLanguageId();
		$manager = \Aimeos\MShop::create( $context, 'attribute/type' );

		if( !isset( $this->attrTypes ) )
		{
			$filter = $manager->filter()->slice( 0, 0x7fffffff );
			$this->attrTypes = $manager->search( $filter )->col( null, 'attribute.type.code' );
		}

		$label = $section['sections_internal_name'][0] ?? '';
		$code = \Aimeos\Base\Str::slug( $section['sections_internal_name'][0] ?? '' ) . '_' . $tid;
		$name = '';

		if( isset( $section['section_header_title'][0] ) ) {
			$name .= '<h2 class="section_header_title">' . strip_tags( $section['section_header_title'][0] ?? '' ) . '</h2>';
		}

		$name .= $section['section_header_subtitle'][0] ?? '';

		$item = $this->attrTypes[$code] ?? $manager->create();
		$item->setDomain( 'product' )->setCode( $code )->setLabel( $label )->setPosition( $pos )->setI18n( [$langId => $name] );

		return $this->attrTypes[$code] = $manager->save( $item );
	}


	/**
	 * Returns the product IDs attached to the given category IDs
	 *
	 * @param array $catIds List of category IDs
	 * @param string $sort Type of sorting, i.e. "ID" or "baseprice"
	 * @param string $dir Sorting direction, "asc" or "desc"
	 * @return array List of sorted product IDs
	 */
	protected function catproducts( array $catIds, string $sort, string $dir ) : array
	{
		$ref = [];
		$sortFcn = function( $a, $b ) {};

		if( $sort === 'baseprice' )
		{
			$ref = ['price'];
			$sortFcn = function( $a, $b ) {
				return $a->getRefItems( 'price', 'default', 'default' )->min( 'price.value' )
					<=>
					$b->getRefItems( 'price', 'default', 'default' )->min( 'price.value' );
			};
		}

		$manager = \Aimeos\MShop::create( $this->context(), 'product' );

		$filter = $manager->filter( true )->slice( 0, 0x7fffffff )->order( 'product.id' );
		$filter->add( $filter->make( 'product:has', ['catalog', 'default', $catIds] ), '!=', null );

		$items = $manager->search( $filter, $ref )->uasort( $sortFcn );

		if( $dir === 'desc' ) {
			$items->reverse();
		}

		return $items->keys()->all();
	}


	protected function createProduct( $code, $name, $desc, $price, $images )
	{
		$manager = \Aimeos\MShop::create( $this->context(), 'product' );

		if( strlen( $code ) > 64 ) {
			$code = substr( $code, 0, 60 ) . '_' . substr( md5( $code ), -3 );
		}

		try {
			$item = $manager->find( $code, ['media', 'price', 'text'] );
		} catch( \Exception $e ) {
			$item = $manager->create()->setCode( $code );
		}

		$mediaListItems = $item->getListItems( 'media', 'default', 'default' )->reverse();
		foreach( array_unique( array_filter( $images ) ) as $pos => $imgurl )
		{
			$listItem = $mediaListItems->pop() ?: $this->listItem();
			$refItem = $listItem->getRefItem() ?: $this->media();
			$refItem->setType( 'default' )->setUrl( $imgurl )->setLabel( $name )->setMimetype( $this->mime( $imgurl ) );
			$item->addListItem( 'media', $listItem, $refItem );
		}
		$item->deleteListItems( $mediaListItems );

		if( $desc )
		{
			$listItem = $item->getListItems( 'text', 'default', 'long' )->first() ?: $this->listItem();
			$refItem = $listItem->getRefItem() ?: $this->text();
			$refItem->setType( 'long' )->setContent( $desc )->setLabel( mb_strcut( $desc, 0, 100 ) );
			$item->addListItem( 'text', $listItem, $refItem );
		}

		if( $price && preg_match( '/^[0-9]+\.?[0-9]*$/', $price ) === 1 )
		{
			$listItem = $item->getListItems( 'price', 'default', 'default' )->first() ?: $this->listItem();
			$refItem = $listItem->getRefItem() ?: $this->price();
			$refItem->setType( 'default' )->setValue( $price );
			$item->addListItem( 'price', $listItem, $refItem );
		}

		$manager->save( $item->setLabel( $name ) );
	}


	/**
	 * Returns an new attribute list item
	 *
	 * @return \Aimeos\MShop\Common\Item\Lists\Iface New list Item
	 */
	protected function listItem() : \Aimeos\MShop\Common\Item\Lists\Iface
	{
		if( !isset( $this->listItem ) ) {
			$this->listItem = \Aimeos\MShop::create( $this->context(), 'attribute' )->createListItem();
		}

		return clone $this->listItem;
	}


	/**
	 * Returns an new media item
	 *
	 * @return \Aimeos\MShop\Media\Item\Iface New media Item
	 */
	protected function media() : \Aimeos\MShop\Media\Item\Iface
	{
		if( !isset( $this->media ) )
		{
			$manager = \Aimeos\MShop::create( $this->context(), 'media' );
			$this->media = $manager->create()->setDomain( 'attribute' );
		}

		return clone $this->media;
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


	/**
	 * Returns an new price item
	 *
	 * @return \Aimeos\MShop\Price\Item\Iface New price Item
	 */
	protected function price() : \Aimeos\MShop\Price\Item\Iface
	{
		if( !isset( $this->price ) )
		{
			$context = $this->context();
			$currencyId = $context->locale()->getCurrencyId();
			$taxrate = $this->db( 'db-woocommerce' )->query( "SELECT tax_rate FROM wp_woocommerce_tax_rates LIMIT 1" )->fetchOne();

			$manager = \Aimeos\MShop::create( $context, 'price' );
			$this->price = $manager->create()->setDomain( 'attribute' )->setCurrencyId( $currencyId )->setTaxrate( $taxrate );
		}

		return clone $this->price;
	}


	/**
	 * Returns an new text item
	 *
	 * @return \Aimeos\MShop\Text\Item\Iface New text Item
	 */
	protected function text() : \Aimeos\MShop\Text\Item\Iface
	{
		if( !isset( $this->text ) )
		{
			$context = $this->context();
			$langId = $context->locale()->getLanguageId();

			$manager = \Aimeos\MShop::create( $context, 'text' );
			$this->text = $manager->create()->setDomain( 'attribute' )->setLanguageId( $langId );
		}

		return clone $this->text;
	}
}
