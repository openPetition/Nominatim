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
        // admin_level=11 is the highest possible value
        if ($iAdminLevel > 11) {
            return [];
        }

        // For the immediate children of the country (admin_level=2 is always
        // the country) we need a special query, because there won't be any
        // records in `place_addressline`
        if ($iAdminLevel == 3) {
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
                AND admin_level = (
                    SELECT DISTINCT p1.admin_level
                    FROM placex AS p1
                    WHERE p1.country_code = :countryCode
                    AND p1.class = 'boundary'
                    AND p1.type = 'administrative'
                    AND p1.osm_type = 'R'
                    AND p1.admin_level > CASE
                        WHEN p1.country_code = 'mt' THEN 3
                        ELSE 2
                        END
                    ORDER BY p1.admin_level ASC
                    LIMIT 1
                )
            ";

            $aResult = $this->oDB->getAll($sSQL, [
                ':countryCode' => $sCountryCode,
            ]);
        } else {
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
                FROM placex AS p1, placex AS p2, place_addressline AS pa
                WHERE p1.osm_id = :osmId
                AND p1.osm_type = 'R'
                AND pa.address_place_id = p1.place_id
                AND pa.place_id = p2.place_id
                AND pa.isaddress
                AND pa.fromarea
                AND p2.admin_level = :adminLevel
                AND p2.class = 'boundary'
                AND p2.type = 'administrative'
                AND p2.osm_type = 'R'
            ";

            $aResult = $this->oDB->getAll($sSQL, [
                ':osmId' => $iOsmId,
                ':adminLevel' => $iAdminLevel,
            ]);
        }

        $aReturn = [];

        if ($aResult) {
            foreach ($aResult as $aResultItem) {
                $aResultItem['children'] = $this->getChildrenRecursively(
                    $aResultItem['osm_id'],
                    $iAdminLevel + 1,
                    $sCountryCode
                );

                $aReturn[] = $aResultItem;
            }
        } else {
            $aReturn = $this->getChildrenRecursively(
                $iOsmId,
                $iAdminLevel + 1,
                $sCountryCode
            );
        } 

        return $aReturn;
    }
}
