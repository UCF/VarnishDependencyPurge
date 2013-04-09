=== Varnish Dependency Purge ===
Contributors: ucfwebcom
Tags: cache, varnish, purge
Requires at least: 3.4.1
Tested up to: 3.4.1
Stable tag: 1.1
License: GPLv3 or later
License URI: http://www.gnu.org/copyleft/gpl-3.0.html

This plugin purges URLs from Varnish caches.

== Description ==

The purpose of this plugin is to allow the use of Varnish caches with WordPress. There are several other plugins that purge Varnish caches from WordPress. These other plugins, though, only purge the permalink of the post that is modified. In complex themes where posts are not neccessarily displayed only on their permalink page, this system won't work. This plugin automatically records the dependency graph of posts and purges all pages on which a post appears.