<?php
/**
 * Source URL helpers — all external links are valid sources.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PIV_Sources {

	const OPTION_PRIMARY = 'piv_primary_domains';
	const OPTION_NEWS    = 'piv_news_domains';
	const OPTION_VERSION = 'piv_db_version';

	/**
	 * Default official publisher / platform domains.
	 *
	 * @return array
	 */
	public static function default_primary_domains() {
		return array(
			'blog.playstation.com',
			'playstation.com',
			'blogs.xbox.com',
			'xbox.com',
			'news.xbox.com',
			'xboxwire.microsoft.com',
			'nintendo.com',
			'store.steampowered.com',
			'steampowered.com',
			'store.epicgames.com',
			'epicgames.com',
			'ea.com',
			'ubisoft.com',
			'rockstargames.com',
			'bethesda.net',
			'activision.com',
			'callofduty.com',
			'blizzard.com',
			'riotgames.com',
			'store.playstation.com',
			'square-enix.com',
			'bandainamcoent.com',
			'capcom.com',
			'sega.com',
			'2k.com',
			'konami.com',
			'cdprojekt.com',
			'insomniac.games',
			'playstationstudios.com',
		);
	}

	/**
	 * Get official domains used for verification and labeling.
	 *
	 * @return array
	 */
	public static function get_primary_domains() {
		$saved = get_option( self::OPTION_PRIMARY, array() );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			$saved = self::default_primary_domains();
		}

		return apply_filters( 'piv_primary_domains', self::normalize_domain_list( $saved ) );
	}

	/**
	 * Default gaming news / media domains.
	 *
	 * @return array
	 */
	public static function default_news_domains() {
		return array(
			'ign.com',
			'il.ign.com',
			'kotaku.com',
			'polygon.com',
			'gamespot.com',
			'eurogamer.net',
			'gamesradar.com',
			'pcgamer.com',
			'vg247.com',
			'destructoid.com',
			'gameinformer.com',
			'rockpapershotgun.com',
			'nintendolife.com',
			'pushsquare.com',
			'purexbox.com',
			'theverge.com',
			'engadget.com',
			'insider-gaming.com',
			'dualshockers.com',
			'wccftech.com',
			'gamesindustry.biz',
			'shacknews.com',
			'siliconera.com',
			'gematsu.com',
			'videogameschronicle.com',
			'thegamer.com',
			'dexerto.com',
			'pcgamesn.com',
			'windowscentral.com',
			'mp1st.com',
			'nintendosoup.com',
			'giantbomb.com',
			'playstationlifestyle.net',
			'attackofthefanboy.com',
			'gamingbolt.com',
			'gamerant.com',
			'noisypixel.net',
			'eventhubs.com',
			'dotesports.com',
			'trueachievements.com',
			'bleedingcool.com',
			'gamesbeat.com',
			'charlieintel.com',
			'exputer.com',
			'tech4gamers.com',
			'segmentnext.com',
			'gamepur.com',
			'gamingintel.com',
			'talkesport.com',
			'esports.gg',
			'gamebyte.com',
			'gamerevolution.com',
			'hardcoregamer.com',
			'videogamer.com',
			'godisageek.com',
			'worthplaying.com',
			'gamepressure.com',
			'playday.one',
			'mynintendonews.com',
			'theloadout.com',
			'finalweapon.net',
			'comicbook.com',
			'screenrant.com',
			'digitaltrends.com',
			'techradar.com',
			'gamezebo.com',
			'massivelyop.com',
			'mmorpg.com',
			'hobbyconsolas.com',
			'everyeye.it',
			// Hebrew / local gaming coverage.
			'games.walla.co.il',
			'geektime.co.il',
			'ice.co.il',
			'gamers-israel.com',
			'gamer-israel.co.il',
		);
	}

	/**
	 * Domains previously shipped that are dead, parked, or no longer gaming news.
	 *
	 * @return array
	 */
	public static function retired_news_domains() {
		return array(
			'gamer.co.il',
			'ign.co.il',
			'games.co.il',
			'gamestar.co.il',
			'powerupgaming.com',
			'xboxera.com',
			'nintendoenthusiast.com',
			'nintendoworldreport.com',
		);
	}

	/**
	 * Merge newly added default domains into saved lists on plugin upgrade.
	 */
	public static function maybe_upgrade_domain_lists() {
		$stored = (string) get_option( self::OPTION_VERSION, '0' );
		if ( version_compare( $stored, PIV_VERSION, '>=' ) ) {
			return;
		}

		self::merge_saved_domains( self::OPTION_PRIMARY, self::default_primary_domains() );
		self::merge_saved_domains( self::OPTION_NEWS, self::default_news_domains(), self::retired_news_domains() );
		update_option( self::OPTION_VERSION, PIV_VERSION );
	}

	/**
	 * @param string $option   Option name.
	 * @param array  $defaults Default domain list.
	 * @param array  $retire   Domains to remove from saved list.
	 */
	private static function merge_saved_domains( $option, $defaults, $retire = array() ) {
		$saved = get_option( $option, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$merged = self::normalize_domain_list( array_merge( $saved, $defaults ) );
		$retire = self::normalize_domain_list( $retire );

		if ( ! empty( $retire ) ) {
			$merged = array_values(
				array_filter(
					$merged,
					static function ( $domain ) use ( $retire ) {
						return ! in_array( $domain, $retire, true );
					}
				)
			);
		}

		update_option( $option, $merged );
	}

	/**
	 * Gaming news domains used for per-post verification scans.
	 *
	 * @return array
	 */
	public static function get_news_domains() {
		$saved = get_option( self::OPTION_NEWS, array() );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			$saved = self::default_news_domains();
		}

		return apply_filters( 'piv_news_domains', self::normalize_domain_list( $saved ) );
	}

	/**
	 * Site domains to exclude from external source checks.
	 *
	 * @return array
	 */
	public static function get_site_domains() {
		$hosts = array(
			self::normalize_domain( home_url() ),
			self::normalize_domain( site_url() ),
		);

		return apply_filters( 'piv_site_domains', self::normalize_domain_list( $hosts ) );
	}

	/**
	 * Normalize a list of domains.
	 *
	 * @param array $domains Raw domains.
	 * @return array
	 */
	public static function normalize_domain_list( $domains ) {
		$out = array();

		foreach ( (array) $domains as $domain ) {
			$domain = self::normalize_domain( $domain );
			if ( $domain ) {
				$out[] = $domain;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Normalize a single domain or URL to bare hostname.
	 *
	 * @param string $value Domain or URL.
	 * @return string
	 */
	public static function normalize_domain( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}

		if ( false !== strpos( $value, '://' ) ) {
			$parsed = wp_parse_url( $value );
			$value  = isset( $parsed['host'] ) ? $parsed['host'] : '';
		}

		$value = preg_replace( '/^www\./', '', $value );
		$value = preg_replace( '/[^a-z0-9.\-]/', '', $value );

		return $value;
	}

	/**
	 * Extract hostname from URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function domain_from_url( $url ) {
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			return '';
		}

		return self::normalize_domain( $parsed['host'] );
	}

	/**
	 * Check if domain matches any entry in list (exact or subdomain).
	 *
	 * @param string $domain Hostname.
	 * @param array  $list   Domain list.
	 * @return bool
	 */
	public static function domain_in_list( $domain, $list ) {
		$domain = self::normalize_domain( $domain );
		if ( '' === $domain ) {
			return false;
		}

		foreach ( $list as $entry ) {
			$entry = self::normalize_domain( $entry );
			if ( $domain === $entry ) {
				return true;
			}
			if ( substr( $domain, - ( strlen( $entry ) + 1 ) ) === '.' . $entry ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether URL points outside the current site.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_external_url( $url ) {
		$domain = self::domain_from_url( $url );
		if ( '' === $domain ) {
			return false;
		}

		foreach ( self::get_site_domains() as $site_domain ) {
			if ( self::domain_in_list( $domain, array( $site_domain ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether domain is a configured official publisher / platform site.
	 *
	 * @param string $domain Hostname.
	 * @return bool
	 */
	public static function is_official_domain( $domain ) {
		return self::domain_in_list( $domain, self::get_primary_domains() );
	}

	/**
	 * Whether domain is a configured gaming news site.
	 *
	 * @param string $domain Hostname.
	 * @return bool
	 */
	public static function is_news_domain( $domain ) {
		return self::domain_in_list( $domain, self::get_news_domains() );
	}

	/**
	 * Whether a URL looks like a specific article/page — not a homepage or listing.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_article_url( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return false;
		}

		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			return false;
		}

		$domain = self::domain_from_url( $url );
		$path   = isset( $parsed['path'] ) ? strtolower( trim( (string) $parsed['path'], '/' ) ) : '';
		$query  = isset( $parsed['query'] ) ? strtolower( (string) $parsed['query'] ) : '';

		if ( '' === $path ) {
			return false;
		}

		if ( '' !== $query && preg_match( '/(?:^|&)(?:q|s|search|query|keyword)=/i', $query ) ) {
			return false;
		}

		$blocked_fragments = array(
			'/search',
			'/tag/',
			'/tags/',
			'/category/',
			'/categories/',
			'/topic/',
			'/topics/',
			'/author/',
			'/authors/',
			'/archive',
			'/archives',
			'/login',
			'/signin',
			'/signup',
			'/register',
			'/account',
			'/privacy',
			'/terms',
			'/cookie',
			'/legal',
			'/about',
			'/contact',
			'/careers',
			'/jobs',
			'/support',
			'/help',
			'/faq',
			'/newsletter',
			'/subscribe',
			'/sitemap',
			'/feed',
			'/rss',
			'/wp-json',
			'/wp-content',
			'/cdn-cgi',
			'/forums',
			'/forum',
			'/community',
			'/deals',
			'/store/',
			'/shop/',
			'/cart',
			'/checkout',
			'/videos/',
			'/video/',
			'/gallery/',
			'/photos/',
			'/podcast',
			'/live/',
			'/hub/',
			'/discover',
			'/trending',
			'/popular',
			'/latest',
			'/platforms',
			'/consoles',
			'/press-kit',
			'/media-kit',
			'/wiki/',
			'/wikia/',
			'/fandom/',
			'/cheats/',
			'/walkthrough/',
			'/walkthroughs/',
			'/spoiler/',
			'/ending-explained/',
			'/game-pass',
			'/gamepass',
			'/ps-plus',
			'/playstation-plus',
			'/subscriptions',
			'/membership',
			'/corporate',
			'/investor',
			'/investors',
			'/trademark',
			'/accessibility',
			'/choose-country',
			'/region/',
			'/locales',
			'/hardware/',
			'/accessories/',
			'/downloads/',
			'/social/',
			'/events/',
			'/expo/',
			'/insider/',
			'/studios/',
			'/entertainment/',
			'/products/',
			'/company/',
			'/brands/',
			'/franchises/',
			'/catalog/',
			'/browse/',
			'/library/',
			'/wishlist',
			'/account/',
			'/my-account',
			'/redeem',
			'/wallet',
			'/bundle',
			'/sale/',
			'/specials',
		);

		$path_slash = '/' . $path . '/';
		foreach ( $blocked_fragments as $fragment ) {
			if ( false !== strpos( $path_slash, $fragment ) ) {
				return false;
			}
		}

		if ( preg_match( '#\.(xml|rss|json|pdf|zip|jpg|jpeg|png|gif|webp|mp4)$#i', $path ) ) {
			return false;
		}

		$segments = array_values( array_filter( explode( '/', $path ) ) );
		if ( empty( $segments ) ) {
			return false;
		}

		$landing_slugs = array(
			'news', 'blog', 'blogs', 'games', 'gaming', 'store', 'shop', 'support',
			'about', 'home', 'index', 'en', 'he', 'us', 'uk', 'en-us', 'en-gb', 'he-il',
			'articles', 'reviews', 'review', 'guides', 'guide', 'features', 'feature',
			'videos', 'podcasts', 'deals', 'platforms', 'consoles', 'topics',
			'ps5', 'ps4', 'ps3', 'xbox', 'switch', 'nintendo', 'pc', 'mobile', 'vr',
			'cloud', 'plus', 'pass', 'direct', 'eshop', 'online', 'download', 'social',
			'expo', 'events', 'insider', 'studios', 'entertainment', 'legal', 'privacy',
			'playstation', 'xbox-series-x', 'xbox-series-s', 'xbox-one', 'hardware',
			'accessories', 'subscriptions', 'membership', 'company', 'corporate',
			'products', 'franchises', 'catalog', 'browse', 'library', 'sale', 'specials',
			'apps', 'software', 'media', 'press', 'investors', 'careers', 'jobs',
		);

		if ( 1 === count( $segments ) && in_array( $segments[0], $landing_slugs, true ) ) {
			return false;
		}

		if ( self::is_official_domain( $domain ) ) {
			return self::is_official_article_url( $url, $domain, $path, $segments, $path_slash );
		}

		if ( preg_match( '#\d{4}[/-]\d{1,2}[/-]\d{1,2}#', $path ) ) {
			return true;
		}

		if ( preg_match( '#/(?:articles?|article|news|story|stories|post|posts|features?|reviews?|guides?|analysis|opinion|report|reports|announcements?|blogs?)/#i', $path_slash ) ) {
			return true;
		}

		// Numeric CMS IDs: /1234567/slug or /games/12345/...
		if ( preg_match( '#/(?:\d{5,}|games/\d+)/#', $path_slash ) ) {
			return true;
		}

		if ( count( $segments ) >= 2 ) {
			$last = end( $segments );
			if ( strlen( $last ) >= 6 || preg_match( '/[-_]/', $last ) || preg_match( '/\d/', $last ) ) {
				return true;
			}
		}

		if ( 1 === count( $segments ) ) {
			$slug = $segments[0];
			// Kotaku/Gizmodo-style long hyphenated slugs.
			if ( strlen( $slug ) >= 14 || ( preg_match( '/[-_]/', $slug ) && strlen( $slug ) >= 10 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Stricter article detection for publisher / platform official sites.
	 *
	 * @param string $url        URL.
	 * @param string $domain     Hostname.
	 * @param string $path       URL path without slashes.
	 * @param array  $segments   Path segments.
	 * @param string $path_slash Path wrapped in slashes.
	 * @return bool
	 */
	public static function is_official_article_url( $url, $domain, $path, $segments, $path_slash ) {
		unset( $url );

		if ( preg_match( '#/app/\d+#', $path_slash ) ) {
			return true;
		}

		if ( preg_match( '#/(?:news|blog|blogs|articles?|stories|posts?|features?|press-releases?|announcements?|updates?)/#i', $path_slash ) ) {
			$parts = explode( '/', trim( $path, '/' ) );
			$last  = end( $parts );
			if ( strlen( (string) $last ) >= 8 || preg_match( '/[-_]/', (string) $last ) || preg_match( '/\d/', (string) $last ) ) {
				return true;
			}
		}

		if ( preg_match( '#\d{4}[/-]\d{1,2}(?:[/-]\d{1,2})?#', $path ) ) {
			return true;
		}

		$locale_pattern = '/^(?:[a-z]{2})(?:-[a-z]{2})?$/';
		$section_slugs  = array(
			'games', 'gaming', 'ps5', 'ps4', 'ps3', 'playstation', 'xbox', 'switch', 'nintendo',
			'store', 'shop', 'support', 'plus', 'pass', 'hardware', 'accessories', 'cloud',
			'subscriptions', 'membership', 'products', 'platforms', 'consoles', 'topics',
			'news', 'blog', 'blogs', 'media', 'press', 'company', 'corporate', 'entertainment',
			'studios', 'events', 'expo', 'insider', 'downloads', 'apps', 'software', 'social',
			'direct', 'eshop', 'online', 'vr', 'pc', 'mobile', 'sale', 'specials', 'catalog',
			'browse', 'library', 'franchises', 'brands', 'legal', 'privacy', 'about', 'home',
		);

		if ( 1 === count( $segments ) && preg_match( $locale_pattern, $segments[0] ) ) {
			return false;
		}

		if ( count( $segments ) >= 2 ) {
			$second = $segments[1];
			if ( preg_match( $locale_pattern, $segments[0] ) && in_array( $second, $section_slugs, true ) ) {
				return false;
			}
			if ( in_array( $second, $section_slugs, true ) && count( $segments ) <= 2 ) {
				return false;
			}
		}

		if ( preg_match( '/(?:blog|news|wire|press)\./', $domain ) ) {
			if ( count( $segments ) >= 2 ) {
				$last = end( $segments );
				if ( strlen( $last ) >= 10 || preg_match( '/[-_]/', $last ) ) {
					return true;
				}
			}
			if ( 1 === count( $segments ) && strlen( $segments[0] ) >= 15 && preg_match( '/[-_]/', $segments[0] ) ) {
				return true;
			}
		}

		if ( count( $segments ) >= 3 ) {
			$last = end( $segments );
			if ( strlen( $last ) >= 12 && ( preg_match( '/[-_]/', $last ) || preg_match( '/\d/', $last ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Pick official domains that are likely relevant to a post.
	 *
	 * @param WP_Post $post       Post object.
	 * @param int     $max        Max domains.
	 * @param array   $cited_urls URLs already cited in the post.
	 * @return array
	 */
	public static function get_official_domains_for_post( $post, $max = 3, $cited_urls = array(), $smart_scan = false ) {
		$max = max( 1, min( 150, (int) $max ) );
		$all = self::get_primary_domains();
		$haystack = mb_strtolower(
			wp_strip_all_tags(
				(string) $post->post_title . ' ' .
				(string) $post->post_excerpt . ' ' .
				(string) $post->post_content
			),
			'UTF-8'
		);

		$matched = array();

		foreach ( (array) $cited_urls as $url ) {
			$domain = self::domain_from_url( $url );
			if ( $domain && self::is_official_domain( $domain ) ) {
				$matched[] = $domain;
			}
		}

		$brand_hints = array(
			'playstation' => array( 'playstation', 'ps5', 'ps4', 'פלייסטיישן', 'פס5', 'פס4' ),
			'xbox'        => array( 'xbox', 'אקסבוקס', 'microsoft gaming' ),
			'nintendo'    => array( 'nintendo', 'switch', 'נינטנדו', 'סוויץ' ),
			'steam'       => array( 'steam', 'סטים', 'valve' ),
			'epicgames'   => array( 'epic games', 'epic', 'fortnite', 'אפיק' ),
			'ea.com'      => array( 'electronic arts', 'ea sports', 'ea ' ),
			'ubisoft'     => array( 'ubisoft', 'יוביסופט' ),
			'rockstar'    => array( 'rockstar', 'gta', 'רוקסטאר' ),
			'bethesda'    => array( 'bethesda', 'elder scrolls', 'fallout' ),
			'activision'  => array( 'activision', 'call of duty', 'cod ' ),
			'callofduty'  => array( 'call of duty', 'cod ', 'קול אוף דיוטי' ),
			'blizzard'    => array( 'blizzard', 'warcraft', 'overwatch', 'diablo', 'בליזארד' ),
			'riot'        => array( 'riot', 'league of legends', 'valorant' ),
			'square'      => array( 'square enix', 'final fantasy', 'kingdom hearts' ),
			'bandai'      => array( 'bandai namco', 'tekken', 'elden ring', 'fromsoftware' ),
			'capcom'      => array( 'capcom', 'street fighter', 'resident evil', 'monster hunter' ),
			'sega'        => array( 'sega', 'sonic', 'yakuza', 'like a dragon' ),
			'2k'          => array( '2k games', 'nba 2k', 'borderlands', 'bioshock' ),
			'konami'      => array( 'konami', 'metal gear', 'silent hill', 'pro evolution' ),
			'cdprojekt'   => array( 'cd projekt', 'cyberpunk', 'witcher' ),
			'insomniac'   => array( 'insomniac', 'ratchet', 'spider-man', 'spiderman' ),
		);

		foreach ( $all as $domain ) {
			foreach ( $brand_hints as $brand => $keywords ) {
				if ( false === strpos( $domain, $brand ) ) {
					continue;
				}

				foreach ( $keywords as $keyword ) {
					if ( false !== mb_strpos( $haystack, $keyword, 0, 'UTF-8' ) ) {
						$matched[] = $domain;
						break 2;
					}
				}
			}
		}

		$matched = array_values( array_unique( array_filter( $matched ) ) );

		if ( $smart_scan ) {
			// Always include brand matches, then fill with top platforms so we don't miss coverage.
			$ordered = $matched;
			$fill_to = empty( $matched ) ? min( 10, $max ) : min( $max, max( count( $matched ) + 4, 8 ) );
			foreach ( $all as $domain ) {
				if ( in_array( $domain, $ordered, true ) ) {
					continue;
				}
				$ordered[] = $domain;
				if ( count( $ordered ) >= $fill_to ) {
					break;
				}
			}

			return array_slice( $ordered, 0, $max );
		}

		$ordered = $matched;

		foreach ( $all as $domain ) {
			if ( in_array( $domain, $ordered, true ) ) {
				continue;
			}
			$ordered[] = $domain;
			if ( count( $ordered ) >= $max ) {
				break;
			}
		}

		return array_slice( $ordered, 0, $max );
	}

	/**
	 * Pick gaming news domains to scan for a post.
	 *
	 * @param WP_Post $post       Post object.
	 * @param int     $max        Max domains.
	 * @param array   $cited_urls URLs cited in the post.
	 * @param bool    $smart_scan Limit scan to cited + top list entries.
	 * @return array
	 */
	public static function get_news_domains_for_post( $post, $max = 5, $cited_urls = array(), $smart_scan = true ) {
		$max = max( 1, min( 150, (int) $max ) );
		$all = self::get_news_domains();
		$matched = array();

		foreach ( (array) $cited_urls as $url ) {
			$domain = self::domain_from_url( $url );
			if ( $domain && self::is_news_domain( $domain ) ) {
				$matched[] = $domain;
			}
		}

		$matched = array_values( array_unique( array_filter( $matched ) ) );

		if ( $smart_scan ) {
			$ordered = $matched;
			$fill_to = empty( $matched ) ? min( 8, $max ) : min( $max, count( $matched ) + 5 );

			foreach ( $all as $domain ) {
				if ( in_array( $domain, $ordered, true ) ) {
					continue;
				}
				$ordered[] = $domain;
				if ( count( $ordered ) >= $fill_to ) {
					break;
				}
			}

			return array_slice( $ordered, 0, $max );
		}

		$ordered = $matched;
		foreach ( $all as $domain ) {
			if ( in_array( $domain, $ordered, true ) ) {
				continue;
			}
			$ordered[] = $domain;
			if ( count( $ordered ) >= $max ) {
				break;
			}
		}

		return array_slice( $ordered, 0, $max );
	}
}
