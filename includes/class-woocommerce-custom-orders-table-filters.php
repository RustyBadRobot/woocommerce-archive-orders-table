<?php
/**
 * Filters to be applied to database queries for orders and refunds.
 *
 * @package WooCommerce_Custom_Orders_Table
 * @author  Liquid Web
 */

/**
 * Query filters for WooCommerce Custom Orders Table.
 */
class WooCommerce_Custom_Orders_Table_Filters {

	/**
	 * Determine if any filters are required on the MySQL query and, if so, apply them.
	 *
	 * @param array $query_args The arguments to be passed to WP_Query.
	 * @param array $query_vars The raw query vars passed to build the query.
	 *
	 * @return array The potentially-filtered $query_args array.
	 */
	public static function filter_database_queries( $query_args, $query_vars ) {
		$query_args['wc_order_meta_query']  = array();
		$query_args['_wc_has_meta_columns'] = false;

		// Iterate over the meta_query to find special cases.
		if ( isset( $query_args['meta_query'] ) ) {
			foreach ( $query_args['meta_query'] as $index => $meta_query ) {

				// Flatten complex meta queries.
				if ( is_array( $meta_query ) && 1 === count( $meta_query ) && is_array( current( $meta_query ) ) ) {
					$meta_query = current( $meta_query );
				}

				if ( isset( $meta_query['customer_emails'] ) ) {
					$query_args['wc_order_meta_query'][] = array_merge(
						$meta_query['customer_emails'],
						array(
							'key'      => 'billing_email',
							'_old_key' => $meta_query['customer_emails']['key'],
						)
					);
				}

				if ( isset( $meta_query['customer_ids'] ) ) {
					$query_args['wc_order_meta_query'][] = array_merge(
						$meta_query['customer_ids'],
						array(
							'key'      => 'customer_id',
							'_old_key' => $meta_query['customer_ids']['key'],
						)
					);
				}

				if ( isset( $meta_query['key'] ) ) {
					$column = array_search( $meta_query['key'], WooCommerce_Custom_Orders_Table::get_postmeta_mapping(), true );

					if ( $column ) {
						$query_args['wc_order_meta_query'][] = array_merge(
							$meta_query,
							array(
								'key'      => $column,
								'_old_key' => $meta_query['key'],
							)
						);
					} else {
						// Let this meta query pass through unaltered.
						$query_args['_wc_has_meta_columns'] = true;
					}
				}
			}
		}

		// Add filters to address specific portions of the query.
		add_filter( 'posts_join', __CLASS__ . '::posts_join', 10, 2 );
		add_filter( 'posts_where', __CLASS__ . '::meta_query_where', 100, 2 );

		return $query_args;
	}

	/**
	 * Filter the JOIN statement generated by WP_Query.
	 *
	 * @global $wpdb
	 *
	 * @param string   $join     The MySQL JOIN statement.
	 * @param WP_Query $wp_query The WP_Query object, passed by reference.
	 *
	 * @return string The filtered JOIN statement.
	 */
	public static function posts_join( $join, $wp_query ) {
		global $wpdb;

		/*
		 * Remove the now-unnecessary INNER JOIN with the post_meta table unless there's some post
		 * meta that doesn't have a column in the custom table.
		 *
		 * @see WP_Meta_Query::get_sql_for_clause()
		 */
		if ( ! $wp_query->get( '_wc_has_meta_columns', false ) ) {
			// Match the post_meta table INNER JOIN, with or without an alias.
			$regex = "/\sINNER\sJOIN\s{$wpdb->postmeta}\s+(AS\s[^\s]+)?\s*ON\s\([^\)]+\)/i";

			$join = preg_replace( $regex, '', $join );
		}

		$table = esc_sql( wc_custom_order_table()->get_table_name() );
		$join .= " LEFT JOIN {$table} ON ( {$wpdb->posts}.ID = {$table}.order_id ) ";

		// Don't necessarily apply this to subsequent posts_join filter callbacks.
		remove_filter( 'posts_join', __CLASS__ . '::posts_join', 10 );

		return $join;
	}

	/**
	 * Filter the "WHERE" portion of the MySQL query to look at the custom orders table instead of
	 * post meta.
	 *
	 * @global $wpdb
	 *
	 * @param string   $where    The MySQL WHERE statement.
	 * @param WP_Query $wp_query The WP_Query object, passed by reference.
	 *
	 * @return string The [potentially-] filtered WHERE statement.
	 */
	public static function meta_query_where( $where, $wp_query ) {
		global $wpdb;

		$meta_query = $wp_query->get( 'wc_order_meta_query' );
		$table      = esc_sql( wc_custom_order_table()->get_table_name() );

		if ( empty( $meta_query ) ) {
			return $where;
		}

		foreach ( $meta_query as $query ) {
			$regex = $wpdb->prepare( '/\(\s?(\w+\.)?meta_key = %s AND (\w+\.)?meta_value /i', $query['_old_key'] );
			$where = preg_replace( $regex, "( {$table}.{$query['key']} ", $where );
		}

		// Ensure this doesn't affect all subsequent queries.
		remove_filter( 'posts_where', __CLASS__ . '::meta_query_where', 100 );

		return $where;
	}

	/**
	 * Filter the query constructed by WC_Admin_Report::get_order_report_data() so that report data
	 * comes from the orders table, not postmeta.
	 *
	 * @global $wpdb
	 *
	 * @param array $query Components of the MySQL query.
	 *
	 * @return array The filtered query components.
	 */
	public static function filter_order_report_query( $query ) {
		global $wpdb;

		if ( empty( $query['join'] ) ) {
			return $query;
		}

		/*
		 * Determine which JOIN statements are in play.
		 *
		 * This regular expression is designed to match queries in the following formats:
		 *
		 * - INNER JOIN $wpdb->postmeta AS meta_{key} ON (post.ID = meta_{key}.post_id AND meta_{key}.meta_key = {key})
		 * - INNER JOIN $wpdb->postmeta AS parent_meta_{key} ON (posts.post_parent = parent_meta_{key}.post_id) AND
		 *   (parent_meta_{key}.meta_key = {key})
		 */
		$regex = '/(?:INNER|LEFT)\s+JOIN\s+' . preg_quote( $wpdb->postmeta, '/' ) . '\s+AS\s((?:parent_)?meta_([^\s]+))\s+ON\s+(\((?:[^)]+\)\s+AND\s+\()?[^\)]+\))/im';

		// Return early if we have no matches.
		if ( ! preg_match_all( $regex, $query['join'], $matches ) ) {
			return $query;
		}

		/*
		 * Build a list of replacements.
		 *
		 * These will take the form of 'meta_{key}.meta_value' => 'meta_{key}.{table_column}'.
		 */
		$mapping      = WooCommerce_Custom_Orders_Table::get_postmeta_mapping();
		$joins        = array(
			'post_id'     => false,
			'post_parent' => false,
		);
		$table        = esc_sql( wc_custom_order_table()->get_table_name() );
		$replacements = array();

		foreach ( $matches[0] as $key => $value ) {
			$table_plus_meta_value = $matches[1][ $key ] . '.meta_value';
			$order_table_column    = array_search( $matches[2][ $key ], $mapping, true );

			// Don't replace the string if there isn't a table column mapped to this key.
			if ( false === $order_table_column ) {
				continue;
			}

			if ( false !== strpos( $matches[3][ $key ], 'posts.post_parent =' ) ) {
				$table_alias          = 'order_parent_meta';
				$joins['post_parent'] = true;
			} else {
				$table_alias      = 'order_meta';
				$joins['post_id'] = true;
			}

			$replacements[ $table_plus_meta_value ] = $table_alias . '.' . $order_table_column;
			$replacements[ $matches[0][ $key ] ]    = '';
		}

		// Update query fragments.
		$replacement_keys = array_keys( $replacements );
		$replacement_vals = array_values( $replacements );
		$query['select']  = str_replace( $replacement_keys, $replacement_vals, $query['select'] );
		$query['where']   = str_replace( $replacement_keys, $replacement_vals, $query['where'] );
		$query['join']    = str_replace( $replacement_keys, $replacement_vals, $query['join'] );

		// If replacements have been made, join on the orders table.
		if ( $joins['post_id'] ) {
			$query['join'] .= " LEFT JOIN {$table} AS order_meta ON ( posts.ID = order_meta.order_id ) ";
		}

		if ( $joins['post_parent'] ) {
			$query['join'] .= " LEFT JOIN {$table} AS order_parent_meta ON ( posts.post_parent = order_parent_meta.order_id ) ";
		}

		return $query;
	}

	/**
	 * When the add_order_indexes system status tool is run, populate missing address indexes in
	 * the orders table.
	 *
	 * @global $wpdb
	 *
	 * @param array $tool Details about the tool that has been executed.
	 */
	public static function rest_populate_address_indexes( $tool ) {
		global $wpdb;

		if ( ! isset( $tool['id'] ) || 'add_order_indexes' !== $tool['id'] ) {
			return;
		}

		$table = wc_custom_order_table()->get_table_name();

		$wpdb->query(
			'UPDATE ' . esc_sql( $table ) . "
			SET billing_index = CONCAT_WS( ' ', billing_first_name, billing_last_name, billing_company, billing_company, billing_address_1, billing_address_2, billing_city, billing_state, billing_postcode, billing_country, billing_email, billing_phone )
			WHERE billing_index IS NULL OR billing_index = ''"
		); // WPCS: DB call ok.
		$wpdb->query(
			'UPDATE ' . esc_sql( $table ) . "
			SET shipping_index = CONCAT_WS( ' ', shipping_first_name, shipping_last_name, shipping_company, shipping_company, shipping_address_1, shipping_address_2, shipping_city, shipping_state, shipping_postcode, shipping_country )
			WHERE shipping_index IS NULL OR shipping_index = ''"
		); // WPCS: DB call ok.
	}

	/**
	 * Associate previous orders from an email address that matches that of a new customer.
	 *
	 * @param int     $order_id The order ID.
	 * @param WP_User $customer The customer object.
	 */
	public static function update_past_customer_order( $order_id, $customer ) {
		$order = wc_get_order( $order_id );
		$order->set_customer_id( $customer->ID );
		$order->save();
	}
}
