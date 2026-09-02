<?php

/*
 * Full-text search limits — these MUST MIRROR the MySQL server's InnoDB
 * FULLTEXT settings, because SearchController::booleanQuery() uses them to
 * drop words the index never stored. Requiring an unindexed word ("+be")
 * matches zero rows and silently kills the whole search.
 *
 * ── Current state (MySQL defaults) ──────────────────────────────────────────
 *   innodb_ft_min_token_size = 3       → 1- and 2-letter words unindexed
 *   default InnoDB stopword list       → the 36 words below unindexed
 *
 * ── The real fix (recommended for a scripture corpus) ───────────────────────
 * Stopwords and short words are CORE Bible vocabulary — "I am", "be", "is",
 * "ye". Make everything searchable on the server, then neuter these guards:
 *
 *   1. On the VPS, edit /etc/mysql/mysql.conf.d/mysqld.cnf, under [mysqld]:
 *          innodb_ft_min_token_size = 1
 *          innodb_ft_enable_stopword = OFF
 *   2. sudo systemctl restart mysql     (min_token_size is not dynamic)
 *   3. Rebuild the verses FULLTEXT index so it re-tokenises under the new
 *      rules (SHOW INDEX FROM verses; gives the index name):
 *          ALTER TABLE verses DROP INDEX verses_text_fulltext;
 *          ALTER TABLE verses ADD FULLTEXT verses_text_fulltext (text);
 *   4. Come back here and set:
 *          'min_token_length' => 1,
 *          'stopwords'        => [],
 *
 * The corpus is small (tens of thousands of verses), so the index growth
 * from single-letter tokens is negligible.
 *
 * ── A NOTE ON THE CEILINGS BELOW ────────────────────────────────────────────
 * Making every word searchable (above) makes common-word searches BIGGER, not
 * smaller — "the" would match essentially the whole Bible. The ceilings at the
 * bottom of this file are what make that safe, so raise them with care.
 */
return [

    // Words shorter than this are absent from the FULLTEXT index.
    // Mirror of innodb_ft_min_token_size.
    'min_token_length' => 3,

    // MySQL InnoDB's default stopword list, verbatim
    // (SELECT * FROM INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD).
    // Mirror of the server's active stopword configuration.
    'stopwords' => [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de',
        'en', 'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of',
        'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when',
        'where', 'who', 'will', 'with', 'und', 'www',
    ],

    /*
     * ── Result ceilings ─────────────────────────────────────────────────────
     * Everything downstream of the query costs memory PER ROW: an Eloquent
     * model, a regex highlight pass, and a result card in the HTML. "lord" in
     * the KJV matches over seven thousand verses, so without a ceiling one
     * anonymous GET renders a multi-megabyte page.
     *
     * Both are enforced in SQL, before any row is hydrated, and both are
     * reported to the reader on the results page rather than applied
     * silently — see search/results.blade.php.
     */

    // Verses rendered on one page.
    'per_page' => 100,

    // How DEEP a reader may page: max_results / per_page pages, ten by
    // default. Since each page fetches only per_page rows, this is no longer
    // about response size — it bounds OFFSET, so ?page=99999 can't ask MySQL
    // to walk a million rows and discard all but a hundred. Results past this
    // depth are reached by FILTERING (the book chips), not by paging further,
    // which is why the chips are built from a facet count over the whole match
    // set rather than from the rows on the current page.
    'max_results' => 1000,

    // Most references one query may name ("john 3:16, mark 2, luke 4, …").
    // Every reference becomes an OR'd clause in one query, so this bounds how
    // wide that WHERE grows — a bare book name means the WHOLE book.
    'max_references' => 20,

];
