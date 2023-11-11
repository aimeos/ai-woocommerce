<?php

/**
 * @license LGPLv3, https://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2023
 */


namespace Aimeos\Upscheme\Task;


class WooMigrateExtraProductsOptions extends Base
{
	private $context;
	private $attrTypes;


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

		$map = [];
		foreach( $result->iterateAssociative() as $row ) {
			$map[$row['catid']][] = $row;
		}

		$this->update( $map );
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


	public function update( array $map )
	{
		foreach( $map as $catid => $list )
		{
			foreach( $list as $row )
			{
				if( ( $content = unserialize( $row['template'] ?? '' ) ) === false ) {
					error_log( sprintf( 'Template for category "%1$s" can not be unserialized', $catid ) );
					continue;
				}

				if( ( $section = $content['tmfbuilder'] ?? null ) === null ) {
					continue;
				}

				if( in_array( 'radiobuttons', $section['element_type'] )
					&& !empty( $section['multiple_radiobuttons_options_enabled'] )
				) {
					$typeItem = $this->attributeType( $row['ID'], $section, $content['priority'] ?? 0 );
					$attrIds = $this->attribute( $row['ID'], $section, $typeItem->getCode() );

					$excluded = unserialize( $row['excludes'] ?? '' ) ?: [];
					$this->assign( $catid, $attrIds, 'attribute', 'config', $excluded );
				}
			}
		}
	}


	protected function assign( string $catid, array $refIds, string $domain, string $listType, array $excluded = [] )
	{
		$context = $this->context();
		$manager = \Aimeos\MShop::create( $context, 'product' );

		$filter = $manager->filter();
		$filter->add( $filter->make( 'product:has', ['catalog', 'default', $catid] ), '!=', null );
		$cursor = $manager->cursor( $filter );

		while( $items = $manager->iterate( $cursor, [$domain] ) )
		{
			foreach( $items as $id => $item )
			{
				if( in_array( $id, $excluded ) ) {
					continue;
				}

				$pos = 0;
				foreach( $refIds as $refId )
				{
					$listItem = $item->getListItem( $domain, $listType, $refId ) ?? $manager->createListItem();
					$item->addListItem( $domain, $listItem->setType( $listType )->setRefId( $refId )->setPosition( $pos++ ) );
				}
			}

			$manager->begin();
			$manager->save( $items );
			$manager->commit();
		}
	}


	protected function attribute( string $tid, array $section, string $typeCode ) : array
	{
		$attrCodes = [];
		$context = $this->context();
		$langId = $context->locale()->getLanguageId();
		$currencyId = $this->context()->locale()->getCurrencyId();

		$attrManager = \Aimeos\MShop::create( $context, 'attribute' );
		$mediaManager = \Aimeos\MShop::create( $context, 'media' );
		$priceManager = \Aimeos\MShop::create( $context, 'price' );
		$textManager = \Aimeos\MShop::create( $context, 'text' );

		$filter = $attrManager->filter()->add( 'attribute.type', '==', $typeCode ) ->slice( 0, 0x7fffffff );
		$attrItems = $attrManager->search( $filter, ['media', 'price', 'product', 'text'] )->col( null, 'attribute.code' );

		$taxrate = $this->db( 'db-woocommerce' )->query( "SELECT tax_rate FROM wp_woocommerce_tax_rates LIMIT 1" )->fetchOne();
		$priceItem = $priceManager->create()->setCurrencyId( $currencyId )->setTaxrate( $taxrate );

		// [0 => ["element" => "60cc52f6238c19.28431944", "rules" => [["section" => "628e0d91c18f96.98756437","element" => "0","operator" => "is","value" => "Chrome"]], ...]
		$prodLogic = array_map( function( $value ) {
			return json_decode( $value ?: '{}', JSON_OBJECT_AS_ARRAY );
		}, $section['product_clogic'] ?? [] );

		foreach( $section['multiple_radiobuttons_options_enabled'] ?? [] as $idx => $list )
		{
			foreach( $list as $key => $status )
			{
				if( !$status ) {
					continue;
				}

				$price = $section['multiple_radiobuttons_options_price'][$idx][$key] ?? 0;
				$name = $section['multiple_radiobuttons_options_title'][$idx][$key] ?? '';
				$image = $section['multiple_radiobuttons_options_image'][$idx][$key] ?? '';
				$lgimage = $section['multiple_radiobuttons_options_imagel'][$idx][$key] ?? '';
				$desc = $section['multiple_radiobuttons_options_description'][$idx][$key] ?? '';
				$code = \Aimeos\Base\Str::slug( $section['multiple_radiobuttons_options_value'][$idx][$key] ?? '' );

				if( !$code ) {
					error_log( 'No value for multiple_radiobuttons_options_value in template ' . $tid );
					continue;
				}

				$code .= '_' . $tid . '_' . $idx . '_' . $key;
				$item = $attrItems[$code] ?? $attrManager->create();
				$item->setCode( $code )->setLabel( $name ?: $code )->setType( $typeCode )->setDomain( 'product' )->setPosition( $key );

				if( $image )
				{
					$listItem = $item->getListItems( 'media', 'default', 'icon' )->first() ?: $attrManager->createListItem();
					$refItem = $listItem->getRefItem() ?: $mediaManager->create();
					$refItem->setDomain( 'attribute' )->setType( 'icon' )->setUrl( $image )->setLabel( $name )->setMimetype( $this->mime( $image ) );
					$item->addListItem( 'media', $listItem, $refItem );
				}

				if( $lgimage )
				{
					$listItem = $item->getListItems( 'media', 'default', 'default' )->first() ?: $attrManager->createListItem();
					$refItem = $listItem->getRefItem() ?: $mediaManager->create();
					$refItem->setDomain( 'attribute' )->setType( 'default' )->setUrl( $lgimage )->setLabel( $name )->setMimetype( $this->mime( $lgimage ) );
					$item->addListItem( 'media', $listItem, $refItem );
				}

				if( $desc )
				{
					$listItem = $item->getListItems( 'text', 'default', 'long' )->first() ?: $attrManager->createListItem();
					$refItem = $listItem->getRefItem() ?: $textManager->create();
					$refItem->setDomain( 'attribute' )->setType( 'long' )->setLanguageId( $langId )->setContent( $desc )->setLabel( mb_strcut( $desc, 0, 100 ) );
					$item->addListItem( 'text', $listItem, $refItem );
				}

				if( $price && preg_match( '/^[0-9]+\.?[0-9]*$/', $price ) === 1 )
				{
					$listItem = $item->getListItems( 'price', 'default', 'default' )->first() ?: $attrManager->createListItem();
					$refItem = $listItem->getRefItem() ?: clone $priceItem;
					$refItem->setDomain( 'attribute' )->setType( 'default' )->setValue( $price );
					$item->addListItem( 'price', $listItem, $refItem );
				}


				$listItems = $item->getListItems( 'product', 'default' )->reverse();

				foreach( $this->products( $section, $prodLogic, $idx, $key ) as $pos => $prodId )
				{
					$listItem = $item->getListItem( 'product', 'default', $prodId ) ?: $attrManager->createListItem();
					$item->addListItem( 'product', $listItem->setRefId( $prodId )->setPosition( $pos ) );
					$listItems->remove( $listItem->getId() );
				}

				$item->deleteListItems( $listItems );


				$attrItems[$code] = $item;
				$attrCodes[] = $code;
			}
		}

		$attrManager->begin();
		$attrManager->save( $attrItems );
		$attrManager->commit();

		return $attrItems->only( $attrCodes )->getId()->all();
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
	 * Returns the sorted product IDs for the option given by index and key
	 *
	 * @param array $section Unserialized template
	 * @param array $prodLogic Unserialized list of product logic sections with rules
	 * @param int $idx Section index
	 * @param int $key Option key
	 * @return array List of sorted product IDs
	 */
	protected function products( array $section, array $prodLogic, int $idx, int $key ) : array
	{
		$prodIds = [];

		// {"section":"628e0d91c18f96.98756437","toggle":"show","what":"all","rules":[{"section":"628c97b82584d9.02380659","element":"0","operator":"is","value":"One%20Tap%20(for%20Single%20Basin)"},{"section":"628c97b82584d9.02380659","element":"1","operator":"is","value":"Deck%20Mounted"}]}
		$secLogic = json_decode( $section['sections_clogic'][$idx] ?? '{}', JSON_OBJECT_AS_ARRAY );

		if( $secKey = $secLogic['section'] ?? null )
		{
			// ['60cc52f6238c19.28431944' => 0, ...]
			$prodKeys = array_flip( $section['product_uniqid'] ?? [] );

			// [0 => {"element":"60cc52f6238c19.28431944","toggle":"show","what":"any","rules":[{"section":"628e0d91c18f96.98756437","element":"0","operator":"is","value":"Chrome"}]}]
			foreach( $prodLogic as $logic )
			{
				$prodSecKey = '';

				foreach( $logic['rules'] ?? [] as $rule )
				{
					if( ( $rule['section'] ?? '' ) === $secKey
						&& ( $section['multiple_radiobuttons_options_value'][$idx][$key] ?? null ) === ( urldecode( $rule['value'] ?? '' ) )
					) {
						$prodSecKey = $logic['element'] ?? '';
					}
				}

				$prodIdx = $prodKeys[$prodSecKey] ?? '';

				if( $section['product_enabled'][$prodIdx] ?? null )
				{
					if( $section['product_mode'][$prodIdx] === 'categories' )
					{
						$catIds = $section['product_categoryids'][$prodIdx] ?? [];
						$sort = $section['product_orderby'][$prodIdx] ?? 'ID';
						$dir = $section['product_order'][$prodIdx] ?? 'asc';

						$prodIds = array_merge( $prodIds, $this->catproducts( $catIds , $sort, $dir ) );
					}
					elseif( $section['product_mode'][$prodIdx] === 'products' )
					{
						$prodIds = array_merge( $prodIds, $section['product_productids'][$prodIdx] ?? [] );
					}
				}
			}
		}

		return $prodIds;
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
}
