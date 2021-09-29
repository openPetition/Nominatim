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
                'children' => $this->getChildrenRecursively(
                    $aPlace['osm_id'],
                    $aPlace['admin_level'] + 1,
                    $aPlace['country_code'],
                ),
            ],
        ];
    }

    private function getChildrenRecursively(int $iOsmId, int $iAdminLevel, string $sCountryCode): array
    {
        // `admin_level=11` is the highest possible value. For more info see
        // https://wiki.openstreetmap.org/wiki/Tag:boundary%3Dadministrative
        if ($iAdminLevel > 11) {
            return [];
        }

        // For the immediate children of the country (admin_level=2 is always
        // the country) we need a special query, because there won't be any
        // records in `place_addressline`
        if ($iAdminLevel == 3) {
            $aResult = $this->getRegionsByCountryCode($sCountryCode);
        } else {
            $aResult = $this->getRegionsByOsmIdAndType($iOsmId, $iAdminLevel);
        }

        $aReturn = [];

        if ($aResult) {
            // If we got some results, get the children (i.e. the regions with
            // the next higher `admin_level`) for each result
            foreach ($aResult as $aResultItem) {
                $aResultItem['children'] = $this->getChildrenRecursively(
                    $aResultItem['osm_id'],
                    $iAdminLevel + 1,
                    $sCountryCode
                );

                $aReturn[] = $aResultItem;
            }
        } else {
            // If there were no results for the current `admin_level`,
            // simply continue with the next one
            $aReturn = $this->getChildrenRecursively(
                $iOsmId,
                $iAdminLevel + 1,
                $sCountryCode
            );
        } 

        return $aReturn;
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

    private function getRegionsByOsmIdAndType(int $iOsmId, int $iAdminLevel): array
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
            FROM place_addressline AS pa
            JOIN placex AS p1
                ON pa.address_place_id = p1.place_id
            JOIN placex AS p2
                ON pa.place_id = p2.place_id
            WHERE p1.osm_id = :osmId
            AND p1.osm_type = 'R'
            AND pa.isaddress
            AND pa.fromarea
            AND p2.admin_level = :adminLevel
            AND p2.class = 'boundary'
            AND p2.type = 'administrative'
            AND p2.osm_type = 'R'
        ";

        return $this->oDB->getAll($sSQL, [
            ':osmId' => $iOsmId,
            ':adminLevel' => $iAdminLevel,
        ]);
    }
}
