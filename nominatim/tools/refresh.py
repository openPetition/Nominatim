"""
Functions for bringing auxiliary data in the database up-to-date.
"""
import logging
from textwrap import dedent
from pathlib import Path

from psycopg2 import sql as pysql

from nominatim.db.utils import execute_file
from nominatim.db.sql_preprocessor import SQLPreprocessor
from nominatim.version import NOMINATIM_VERSION

LOG = logging.getLogger()


def _add_address_level_rows_from_entry(rows, entry):
    """ Converts a single entry from the JSON format for address rank
        descriptions into a flat format suitable for inserting into a
        PostgreSQL table and adds these lines to `rows`.
    """
    countries = entry.get('countries') or (None, )
    for key, values in entry['tags'].items():
        for value, ranks in values.items():
            if isinstance(ranks, list):
                rank_search, rank_address = ranks
            else:
                rank_search = rank_address = ranks
            if not value:
                value = None
            for country in countries:
                rows.append((country, key, value, rank_search, rank_address))

def load_address_levels(conn, table, levels):
    """ Replace the `address_levels` table with the contents of `levels'.

        A new table is created any previously existing table is dropped.
        The table has the following columns:
            country, class, type, rank_search, rank_address
    """
    rows = []
    for entry in levels:
        _add_address_level_rows_from_entry(rows, entry)

    with conn.cursor() as cur:
        cur.drop_table(table)

        cur.execute("""CREATE TABLE {} (country_code varchar(2),
                                        class TEXT,
                                        type TEXT,
                                        rank_search SMALLINT,
                                        rank_address SMALLINT)""".format(table))

        cur.execute_values(pysql.SQL("INSERT INTO {} VALUES %s")
                           .format(pysql.Identifier(table)), rows)

        cur.execute('CREATE UNIQUE INDEX ON {} (country_code, class, type)'.format(table))

    conn.commit()


def load_address_levels_from_config(conn, config):
    """ Replace the `address_levels` table with the content as
        defined in the given configuration. Uses the parameter
        NOMINATIM_ADDRESS_LEVEL_CONFIG to determine the location of the
        configuration file.
    """
    cfg = config.load_sub_configuration('', config='ADDRESS_LEVEL_CONFIG')
    load_address_levels(conn, 'address_levels', cfg)


def create_functions(conn, config, enable_diff_updates=True, enable_debug=False):
    """ (Re)create the PL/pgSQL functions.
    """
    sql = SQLPreprocessor(conn, config)

    sql.run_sql_file(conn, 'functions.sql',
                     disable_diff_updates=not enable_diff_updates,
                     debug=enable_debug)



WEBSITE_SCRIPTS = (
    'deletable.php',
    'details.php',
    'hierarchy.php',
    'lookup.php',
    'polygons.php',
    'reverse.php',
    'search.php',
    'status.php'
)

# constants needed by PHP scripts: PHP name, config name, type
PHP_CONST_DEFS = (
    ('Database_DSN', 'DATABASE_DSN', str),
    ('Default_Language', 'DEFAULT_LANGUAGE', str),
    ('Log_DB', 'LOG_DB', bool),
    ('Log_File', 'LOG_FILE', Path),
    ('NoAccessControl', 'CORS_NOACCESSCONTROL', bool),
    ('Places_Max_ID_count', 'LOOKUP_MAX_COUNT', int),
    ('PolygonOutput_MaximumTypes', 'POLYGON_OUTPUT_MAX_TYPES', int),
    ('Search_BatchMode', 'SEARCH_BATCH_MODE', bool),
    ('Search_NameOnlySearchFrequencyThreshold', 'SEARCH_NAME_ONLY_THRESHOLD', str),
    ('Use_US_Tiger_Data', 'USE_US_TIGER_DATA', bool),
    ('MapIcon_URL', 'MAPICON_URL', str),
)


def import_wikipedia_articles(dsn, data_path, ignore_errors=False):
    """ Replaces the wikipedia importance tables with new data.
        The import is run in a single transaction so that the new data
        is replace seemlessly.

        Returns 0 if all was well and 1 if the importance file could not
        be found. Throws an exception if there was an error reading the file.
    """
    datafile = data_path / 'wikimedia-importance.sql.gz'

    if not datafile.exists():
        return 1

    pre_code = """BEGIN;
                  DROP TABLE IF EXISTS "wikipedia_article";
                  DROP TABLE IF EXISTS "wikipedia_redirect"
               """
    post_code = "COMMIT"
    execute_file(dsn, datafile, ignore_errors=ignore_errors,
                 pre_code=pre_code, post_code=post_code)

    return 0


def recompute_importance(conn):
    """ Recompute wikipedia links and importance for all entries in placex.
        This is a long-running operations that must not be executed in
        parallel with updates.
    """
    with conn.cursor() as cur:
        cur.execute('ALTER TABLE placex DISABLE TRIGGER ALL')
        cur.execute("""
            UPDATE placex SET (wikipedia, importance) =
               (SELECT wikipedia, importance
                FROM compute_importance(extratags, country_code, osm_type, osm_id))
            """)
        cur.execute("""
            UPDATE placex s SET wikipedia = d.wikipedia, importance = d.importance
             FROM placex d
             WHERE s.place_id = d.linked_place_id and d.wikipedia is not null
                   and (s.wikipedia is null or s.importance < d.importance);
            """)

        cur.execute('ALTER TABLE placex ENABLE TRIGGER ALL')
    conn.commit()


def _quote_php_variable(var_type, config, conf_name):
    if var_type == bool:
        return 'true' if config.get_bool(conf_name) else 'false'

    if var_type == int:
        return getattr(config, conf_name)

    if not getattr(config, conf_name):
        return 'false'

    if var_type == Path:
        value = str(config.get_path(conf_name))
    else:
        value = getattr(config, conf_name)

    quoted = value.replace("'", "\\'")
    return f"'{quoted}'"


def setup_website(basedir, config, conn):
    """ Create the website script stubs.
    """
    if not basedir.exists():
        LOG.info('Creating website directory.')
        basedir.mkdir()

    template = dedent("""\
                      <?php

                      @define('CONST_Debug', $_GET['debug'] ?? false);
                      @define('CONST_LibDir', '{0}');
                      @define('CONST_TokenizerDir', '{2}');
                      @define('CONST_NominatimVersion', '{1[0]}.{1[1]}.{1[2]}-{1[3]}');

                      """.format(config.lib_dir.php, NOMINATIM_VERSION,
                                 config.project_dir / 'tokenizer'))

    for php_name, conf_name, var_type in PHP_CONST_DEFS:
        varout = _quote_php_variable(var_type, config, conf_name)

        template += f"@define('CONST_{php_name}', {varout});\n"

    template += f"\nrequire_once('{config.lib_dir.php}/website/{{}}');\n"

    search_name_table_exists = bool(conn and conn.table_exists('search_name'))

    for script in WEBSITE_SCRIPTS:
        if not search_name_table_exists and script == 'search.php':
            (basedir / script).write_text(template.format('reverse-only-search.php'), 'utf-8')
        else:
            (basedir / script).write_text(template.format(script), 'utf-8')
