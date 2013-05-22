<?php

/*
Plugin Name: Gibson Cache
Description: Gibson Cache backend for the WP Object Cache.
Version: 1.0.0
Plugin URI: http://www.emoticode.net/
Author: Simone Margaritelli

Install this file to wp-content/object-cache.php
*/

require dirname( realpath( __FILE__ ) ).'/gibson.class.php';
/*
 * Users with setups where multiple installs share a common wp-config.php or $table_prefix
 * can use this to guarantee uniqueness for the keys generated by this object cache
 */
if ( !defined( 'WP_CACHE_KEY_SALT' ) )
	define( 'WP_CACHE_KEY_SALT', '' );

function wp_cache_add($key, $data, $group = '', $expire = 0) {
	global $wp_object_cache;

	return $wp_object_cache->add($key, $data, $group, $expire);
}

function wp_cache_incr($key, $n = 1, $group = '') {
	global $wp_object_cache;

	return $wp_object_cache->incr($key, $n, $group);
}

function wp_cache_decr($key, $n = 1, $group = '') {
	global $wp_object_cache;

	return $wp_object_cache->decr($key, $n, $group);
}

function wp_cache_close() {
	global $wp_object_cache;

	return $wp_object_cache->close();
}

function wp_cache_delete($key, $group = '') {
	global $wp_object_cache;

	return $wp_object_cache->delete($key, $group);
}

function wp_cache_flush() {
	global $wp_object_cache;

	return $wp_object_cache->flush();
}

function wp_cache_get($key, $group = '', $force = false) {
	global $wp_object_cache;

	return $wp_object_cache->get($key, $group, $force);
}

function wp_cache_init() {
	global $wp_object_cache;

	$wp_object_cache = new WP_Object_Cache();
}

function wp_cache_replace($key, $data, $group = '', $expire = 0) {
	global $wp_object_cache;

	return $wp_object_cache->replace($key, $data, $group, $expire);
}

function wp_cache_set($key, $data, $group = '', $expire = 0) {
	global $wp_object_cache;

	if ( defined('WP_INSTALLING') == false )
		return $wp_object_cache->set($key, $data, $group, $expire);
	else
		return $wp_object_cache->delete($key, $group);
}

function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;

	$wp_object_cache->add_global_groups($groups);
}

function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;

	$wp_object_cache->add_non_persistent_groups($groups);
}

class WP_Object_Cache
{
	var $global_groups = array();
	var $no_mc_groups = array();

	var $cache = array();
	var $gb = NULL;
	var $stats = array();
	var $group_ops = array();

	var $cache_enabled = true;
	var $default_expiration = -1;

	function WP_Object_Cache() {
		$this->gb = new Gibson( 'unix:///var/run/gibson.sock' );

		global $blog_id, $table_prefix;
		$this->global_prefix = '';
		$this->blog_prefix = '';
		if ( function_exists( 'is_multisite' ) ) {
			$this->global_prefix = ( is_multisite() || defined('CUSTOM_USER_TABLE') && defined('CUSTOM_USER_META_TABLE') ) ? '' : $table_prefix;
			$this->blog_prefix = ( is_multisite() ? $blog_id : $table_prefix ) . ':';
		}
		else {
			$this->global_prefix = "gbwp_";
			$this->blog_prefix = "gbwp_b?_";
		}
	}

	function add($id, $data, $group = 'default', $expire = 0) {
		$key = $this->key($id, $group);

		if ( is_object( $data ) )
			$data = clone $data;

		$data = serialize($data);

		if ( in_array($group, $this->no_mc_groups) ) {
			$this->cache[$key] = $data;
			return TRUE;
		} elseif ( isset($this->cache[$key]) && $this->cache[$key] !== FALSE ) {
			return FALSE;
		}

		$result = $this->gb->set( $key, $data );
		if( $result !== FALSE )
		{
			$this->cache[$key] = $data;

			if( $expire > 0 )
				$this->gb->ttl( $key, $expire );
		}

		return $result;
	}

	function add_global_groups($groups) {
		if ( ! is_array($groups) )
			$groups = (array) $groups;

		$this->global_groups = array_merge($this->global_groups, $groups);
		$this->global_groups = array_unique($this->global_groups);
	}

	function add_non_persistent_groups($groups) {
		if ( ! is_array($groups) )
			$groups = (array) $groups;

		$this->no_mc_groups = array_merge($this->no_mc_groups, $groups);
		$this->no_mc_groups = array_unique($this->no_mc_groups);
	}

	function incr($id, $n = 1, $group = 'default' ) {
		$key = $this->key($id, $group);

		for( $i = 0; $i < $n; $i++ )
			$repl = $this->gb->inc( $key );

		if( $repl !== FALSE )
			$this->cache[$key] = serialize( $repl );
		
		return $repl;
	}

	function decr($id, $n = 1, $group = 'default' ) {
		$key = $this->key($id, $group);

		for( $i = 0; $i < $n; $i++ )
			$repl = $this->gb->dec( $key );

		if( $repl !== FALSE )
			$this->cache[$key] = serialize( $repl );
		
		return $repl;
	}

	function delete($id, $group = 'default') {
		$key = $this->key($id, $group);

		if ( in_array($group, $this->no_mc_groups) ) {
			unset($this->cache[$key]);
			return true;
		}

		$result = $this->gb->del($key);

		@ ++$this->stats['delete'];
		$this->group_ops[$group][] = "delete $id";

		if ( false !== $result )
			unset($this->cache[$key]);

		return $result;
	}
	
	function get($id, $group = 'default', $force = false) {
		$key = $this->key($id, $group);

		if ( isset($this->cache[$key]) && ( !$force || in_array($group, $this->no_mc_groups) ) ) {
			$value = $this->cache[$key];
		} else if ( in_array($group, $this->no_mc_groups) ) {
			$this->cache[$key] = $value = false;
		} else {
			$value = $this->gb->get($key);
			if ( FALSE === $value )
				$value = false;
			$this->cache[$key] = $value;
		}

		@ ++$this->stats['get'];
		$this->group_ops[$group][] = "get $id";

		if ( 'checkthedatabaseplease' === $value ) {
			unset( $this->cache[$key] );
			$value = false;
		}

		return $value ? unserialize( $value ) : FALSE;
	}

	function get_multi( $groups ) {
		/*
		 format: $get['group-name'] = array( 'key1', 'key2' );
		*/
		$return = array();

		foreach ( $groups as $group => $ids ) {
			foreach ( $ids as $id ) {
				$key = $this->key($id, $group);
				$return[$key] = $this->get($id, $group);
			}
		}

		@ ++$this->stats['get_multi'];
		$this->group_ops[$group][] = "get_multi $id";
		$this->cache = array_merge( $this->cache, $return );

		$uns = array();
		foreach( $return as $k => $v )
			$uns[$k] = $v ? unserialize($v) : FALSE;

		return $uns;
	}

	function key($key, $group) {
		if ( empty($group) )
			$group = 'default';

		if ( false !== array_search($group, $this->global_groups) )
			$prefix = $this->global_prefix;
		else
			$prefix = $this->blog_prefix;

		return preg_replace('/\s+/', '', WP_CACHE_KEY_SALT . "$prefix$group:$key" );
	}

	function replace($id, $data, $group = 'default', $expire = 0) {
		return $this->add($id, $data, $group, $expire);
	}

	function set($id, $data, $group = 'default', $expire = 0) {
		return $this->add($id, $data, $group, $expire);
	}
	
	function flush() {
		// Don't flush if multi-blog.
		if ( function_exists('is_site_admin') || defined('CUSTOM_USER_TABLE') && defined('CUSTOM_USER_META_TABLE') )
			return true;

		$this->gb->mdel( $this->global_prefix.':' );
		$this->gb->mdel( $this->blog_prefix.':' );
		
		return TRUE;
	}
	
	function close() {
		// TODO: Close connection ( is this needed ? Gibson will handle dead clients anyway ... )
	}

	function failure_callback($host, $port) {
		// TODO: Some error_log maybe ?
	}
}
?>