<?php

namespace Nominatim;

class Hierarchy
{
    protected $oDB;

    public function __construct(DB &$oDB)
    {
        $this->oDB =& $oDB;
    }

    public function getChildren(array $aPlace): array
    {
        if ($aPlace['osm_type'] != 'R') {
            throw new \InvalidArgumentException(sprintf(
                '$aPlace must be a relation - %s given',
                $aPlace['osm_type']
            ));
        }

        $aRegions = array_merge(
            $this->getFirstLevelRegionsByCountryCode($aPlace['country_code']),
            $this->getRegionsByCountryCode($aPlace['country_code'])
        );

        return [
            [
                'osm_type' => $aPlace['osm_type'],
                'osm_id' => $aPlace['osm_id'],
                'name' => $aPlace['placename'],
                'indexed_date' => $this->oDB->getOne("
                    SELECT TO_CHAR(
                        TO_TIMESTAMP(EXTRACT(epoch FROM indexed_date)),
                        'YYYY-MM-DD\"T\"HH:MI:SS+00:00'
                    )
                    FROM placex
                    WHERE place_id = :placeId
                ", [':placeId' => $aPlace['place_id']]),
                'children' => $this->convertToTree($aRegions),
            ],
        ];
    }

    private function convertToTree(array $aElements, int $iParentOsmId = null): array
    {
        $aTree = [];

        foreach ($aElements as $aElement) {
            if ($aElement['parent_osm_id'] == $iParentOsmId) {
                $aTree[] = [
                    'osm_type' => $aElement['osm_type'],
                    'osm_id' => $aElement['osm_id'],
                    'name' => $aElement['name'],
                    'indexed_date' => $aElement['indexed_date'],
                    'children' => $this->convertToTree($aElements, $aElement['osm_id']),
                ];
            }
        }

        return $aTree;
    }

    private function getFirstLevelRegionsByCountryCode(string $sCountryCode): array
    {
        $sSQL = "
            SELECT
                p2.osm_type,
                p2.osm_id,
                COALESCE(
                    p2.name -> 'int_name',
                    p2.name -> 'alt_name',
                    p2.name -> 'name'
                ) AS name,
                TO_CHAR(
                    TO_TIMESTAMP(EXTRACT(epoch FROM p2.indexed_date)),
                    'YYYY-MM-DD\"T\"HH:MI:SS+00:00'
                ) AS indexed_date,
                NULL as parent_osm_id
            FROM placex AS p2
            WHERE p2.country_code = :countryCode
            AND p2.class = 'boundary'
            AND p2.type = 'administrative'
            AND p2.osm_type = 'R'
            AND p2.admin_level = (
                SELECT DISTINCT p1.admin_level
                FROM placex AS p1
                WHERE p1.country_code = :countryCode
                AND p1.class = 'boundary'
                AND p1.type = 'administrative'
                AND p1.osm_type = 'R'
                -- Actually, we just want to have the next level below the
                -- country - i.e. `admin_level > 2`. For some countries (e.g.
                -- Malta) there is unfortunately still an unsightly administration
                -- on a level in between. So unfortunately we have to exclude
                -- these manually.
                AND p1.admin_level > CASE
                    WHEN p1.country_code = 'mt' THEN 3
                    ELSE 2
                    END
                ORDER BY p1.admin_level ASC
                LIMIT 1
            )
            AND (
                -- There are a few ugly regions (e.g. R2869330 or R3659532)
                -- which are not actually ordinary administrations of the country
                -- and thus should not be part of the hierarchy.
                -- We try to exclude these using the ISO3166-2 code. The assumption
                -- is that as soon as at least one administration has this code,
                -- all valid administrations of this country also have the code.
                -- Except for such ugly regions. Thus, we only take the regions
                -- with the (correct) code.
                NOT EXISTS (
                    SELECT 1
                    FROM placex AS p3
                    WHERE p3.country_code = :countryCode
                    AND p3.class = 'boundary'
                    AND p3.type = 'administrative'
                    AND p3.osm_type = 'R'
                    AND p3.extratags -> 'ISO3166-2' IS NOT NULL
                    AND p3.admin_level = p2.admin_level
                )
                OR LOWER(SUBSTRING(extratags -> 'ISO3166-2' FROM '([A-Z]+)-')) = :countryCode
            )
        ";

        return $this->oDB->getAll($sSQL, [
            ':countryCode' => $sCountryCode,
        ]);
    }

    private function getRegionsByCountryCode(string $sCountryCode): array
    {
        $sSQL = "
            WITH links AS (
              SELECT
                p.place_id,
                p.admin_level,
                (
                  SELECT pa.address_place_id
                  FROM place_addressline pa
                  INNER JOIN placex pi
                    ON (pa.address_place_id = pi.place_id)
                  WHERE pa.place_id = p.place_id
                  AND admin_level <= 11
                  ORDER BY admin_level DESC
                  LIMIT 1
                ) AS parent_place_id
              FROM placex p
              WHERE p.class = 'boundary'
                AND p.type = 'administrative'
                AND p.osm_type = 'R'
                AND p.country_code = :countryCode
              ORDER BY p.admin_level DESC
            )
            SELECT
              region.osm_type,
              region.osm_id,
              COALESCE(
                region.name -> 'int_name',
                region.name -> 'alt_name',
                region.name -> 'name'
              ) AS name,
              TO_CHAR(
                TO_TIMESTAMP(EXTRACT(epoch FROM region.indexed_date)),
                'YYYY-MM-DD\"T\"HH:MI:SS+00:00'
              ) AS indexed_date,
              parent.osm_id AS parent_osm_id,
              parent.admin_level AS parent_admin_level
            FROM links
            INNER JOIN placex region
              ON (links.place_id = region.place_id)
            LEFT JOIN placex parent
              ON (links.parent_place_id = parent.place_id)
            -- According to https://wiki.openstreetmap.org/wiki/Tag:boundary%3Dadministrative
            -- 11 should be the highest possible level. Some things, like
            -- postcodes, have a higher admin_level, but we want to ignore
            -- those.
            WHERE region.admin_level <= 11
            AND parent.osm_id IS NOT NULL
            ORDER BY region.admin_level, parent.osm_id
        ";

        return $this->oDB->getAll($sSQL, [
            ':countryCode' => $sCountryCode,
        ]);
    }
}
