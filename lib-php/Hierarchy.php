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

        // The regions for the first level below the country are stored separately,
        // so we have to get those first. Afterwards we can use the OSM IDs to get
        // all the children.
        $aRegionsFirstLevel = $this->getRegionsByCountryCode($aPlace['country_code']);

        $aRegions = array_merge(
            $aRegionsFirstLevel,
            $this->getRegionsByOsmIds(array_map(function (array $aRegionFirstLevel) {
                return $aRegionFirstLevel['osm_id'];
            }, $aRegionsFirstLevel))
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

    private function getRegionsByCountryCode(string $sCountryCode): array
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
                ) AS indexed_date
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

    private function getRegionsByOsmIds(array $aOsmIds): array
    {
        $sOsmIdsPlaceholder = implode(',', array_fill(0, count($aOsmIds), '?'));
        $sSQL = "
            SELECT *
            FROM (
                SELECT DISTINCT ON (descendant.admin_level, descendant.osm_id, descendant.osm_type)
                    descendant.osm_type,
                    descendant.osm_id,
                    COALESCE(
                        descendant.name -> 'int_name',
                        descendant.name -> 'alt_name',
                        descendant.name -> 'name'
                    ) AS name,
                    TO_CHAR(
                        TO_TIMESTAMP(EXTRACT(epoch FROM descendant.indexed_date)),
                        'YYYY-MM-DD\"T\"HH:MI:SS+00:00'
                    ) AS indexed_date,
                    ancestor.osm_id as parent_osm_id,
                    ancestor.admin_level as parent_admin_level

                FROM placex AS base_region
                LEFT JOIN place_addressline AS base_region_descendants
                    ON (base_region_descendants.address_place_id = base_region.place_id)
                LEFT JOIN placex AS descendant
                    ON (base_region_descendants.place_id = descendant.place_id)
                LEFT JOIN place_addressline as ancestors
                    ON (base_region_descendants.place_id = ancestors.place_id)
                LEFT JOIN placex as ancestor
                    ON (ancestor.place_id = ancestors.address_place_id)

                WHERE base_region.osm_id IN ($sOsmIdsPlaceholder)
                AND base_region.osm_type = 'R'
                AND base_region_descendants.isaddress
                AND base_region_descendants.fromarea
                AND descendant.class = 'boundary'
                AND descendant.type = 'administrative'
                AND descendant.osm_type = 'R'

                ORDER BY
                    descendant.admin_level,
                    descendant.osm_id,
                    descendant.osm_type,
                    ancestor.admin_level DESC
            ) AS x
            ORDER BY
                parent_admin_level,
                parent_osm_id
        ";

        return $this->oDB->getAll($sSQL, $aOsmIds);
    }
}
