<?php

/*
 *  LMS version 1.11-git
 *
 *  Copyright (C) 2001-2021 LMS Developers
 *
 *  Please, see the doc/AUTHORS for more information about authors!
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License Version 2 as
 *  published by the Free Software Foundation.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307,
 *  USA.
 *
 *  $Id$
 */

/**
 * LMSNodeManager
 *
 */
class LMSNodeManager extends LMSManager implements LMSNodeManagerInterface
{

    public function GetNodeOwner($id)
    {
        return $this->db->GetOne('SELECT ownerid FROM vnodes WHERE id=?', array($id));
    }

    public function NodeUpdate($nodedata, $deleteassignments = false)
    {
        static $node_empty_mac = null;

        $args = array(
            'name' => ConfigHelper::checkConfig('nodes.capitalize_names', ConfigHelper::checkConfig('phpui.capitalize_node_names', true))
                ? strtoupper($nodedata['name']) : $nodedata['name'],
            'ipaddr_pub'        => $nodedata['ipaddr_pub'],
            'ipaddr'            => $nodedata['ipaddr'],
            'login'             => empty($nodedata['login']) ? null : $nodedata['login'],
            'passwd'            => $nodedata['passwd'],
            SYSLOG::RES_NETDEV  => empty($nodedata['netdev']) ? null : $nodedata['netdev'],
            SYSLOG::RES_USER    => Auth::GetCurrentUser(),
            'access'            => $nodedata['access'],
            'warning'           => $nodedata['warning'],
            SYSLOG::RES_CUST    => empty($nodedata['ownerid']) ? null : $nodedata['ownerid'],
            'info'              => Utils::removeInsecureHtml($nodedata['info']),
            'chkmac'            => $nodedata['chkmac'],
            'halfduplex'        => $nodedata['halfduplex'],
            'linktype'          => isset($nodedata['linktype']) && (is_int($nodedata['linktype']) || ctype_digit($nodedata['linktype']))
                ? intval($nodedata['linktype']) : null,
            'linkradiosector'   => isset($nodedata['linktype']) && (is_int($nodedata['linktype']) || ctype_digit($nodedata['linktype']))
                && intval($nodedata['linktype']) == LINKTYPE_WIRELESS
                && !empty($nodedata['radiosector']) && (is_int($nodedata['radiosector']) || ctype_digit($nodedata['radiosector']))
                ? intval($nodedata['radiosector']) : null,
            'linktechnology'    => !empty($nodedata['linktechnology']) && (is_int($nodedata['linktechnology']) || ctype_digit($nodedata['linktechnology']))
                ? intval($nodedata['linktechnology']) : null,
            'linkspeed'         => !empty($nodedata['linkspeed']) && (is_int($nodedata['linkspeed']) || ctype_digit($nodedata['linkspeed']))
                ? intval($nodedata['linkspeed']) : null,
            'port'              => isset($nodedata['port']) && $nodedata['netdev'] ? intval($nodedata['port']) : 0,
            'nas'               => $nodedata['nas'] ?? 0,
            'longitude'         => !empty($nodedata['longitude']) ? str_replace(',', '.', $nodedata['longitude']) : null,
            'latitude'          => !empty($nodedata['latitude'])  ? str_replace(',', '.', $nodedata['latitude'])  : null,
            SYSLOG::RES_NETWORK => $nodedata['netid'],
            'invprojectid'      => empty($nodedata['invprojectid']) ? null : $nodedata['invprojectid'],
            'authtype'          => $nodedata['authtype']   ?: 0,
            'address_id'        => isset($nodedata['address_id']) && $nodedata['address_id'] >= 0 ? $nodedata['address_id'] : null,
            SYSLOG::RES_NODE    => $nodedata['id']
        );

        $this->db->Execute('UPDATE nodes SET name=?, ipaddr_pub=inet_aton(?),
				ipaddr=inet_aton(?), login=?, passwd=?, netdev=?, moddate=?NOW?,
				modid=?, access=?, warning=?, ownerid=?, info=?,
				chkmac=?, halfduplex=?, linktype=?, linkradiosector=?, linktechnology=?, linkspeed=?,
				port=?, nas=?, longitude=?, latitude=?, netid=?, invprojectid=?, authtype=?, address_id=?
				WHERE id=?', array_values($args));

        if ($this->syslog) {
            unset($args[SYSLOG::RES_USER]);
            $this->syslog->AddMessage(SYSLOG::RES_NODE, SYSLOG::OPER_UPDATE, $args);

            $macs = $this->db->GetAll(
                'SELECT id, nodeid
                    FROM macs
                    WHERE nodeid = ?',
                array(
                    $nodedata['id'],
                )
            );
            if (!empty($macs)) {
                foreach ($macs as $mac) {
                    $args = array(
                        SYSLOG::RES_MAC => $mac['id'],
                        SYSLOG::RES_NODE => $mac['nodeid'],
                        SYSLOG::RES_CUST => $nodedata['ownerid']
                    );
                    $this->syslog->AddMessage(SYSLOG::RES_MAC, SYSLOG::OPER_DELETE, $args);
                }
            }
        }

        $this->db->Execute('DELETE FROM macs WHERE nodeid=?', array($nodedata['id']));

        if (!isset($node_empty_mac)) {
            $node_empty_mac = ConfigHelper::getConfig('nodes.empty_mac', ConfigHelper::getConfig('phpui.node_empty_mac', '', true));
            if (strlen($node_empty_mac) && check_mac($node_empty_mac)) {
                $node_empty_mac = Utils::normalizeMac($node_empty_mac);
            } else {
                $node_empty_mac = '';
            }
        }

        if (!empty($nodedata['macs'])) {
            foreach ($nodedata['macs'] as $mac) {
                $mac = strtoupper($mac);

                $this->db->Execute('INSERT INTO macs (mac, nodeid) VALUES(?, ?)', array($mac, $nodedata['id']));

                if ($this->syslog && $mac != $node_empty_mac) {
                    $macid = $this->db->GetLastInsertID('macs');
                    $args = array(
                        SYSLOG::RES_MAC => $macid,
                        SYSLOG::RES_NODE => $nodedata['id'],
                        SYSLOG::RES_CUST => $nodedata['ownerid'],
                        'mac' => $mac
                    );
                    $this->syslog->AddMessage(SYSLOG::RES_MAC, SYSLOG::OPER_ADD, $args);
                }
            }
        } elseif (strlen($node_empty_mac)) {
            $this->db->Execute(
                'INSERT INTO macs (mac, nodeid) VALUES (?, ?)',
                array(
                    $node_empty_mac,
                    $nodedata['id'],
                )
            );
        }

        if ($deleteassignments) {
            if ($this->syslog) {
                $nodeassigns = $this->db->GetAll('SELECT id, nodeid, assignmentid FROM nodeassignments
					WHERE nodeid = ?', array($nodedata['id']));
                if (!empty($nodeassigns)) {
                    foreach ($nodeassigns as $nodeassign) {
                        $args = array(
                        SYSLOG::RES_NODEASSIGN => $nodeassign['id'],
                        SYSLOG::RES_NODE => $nodedata['id'],
                        SYSLOG::RES_ASSIGN => $nodeassign['assignmentid'],
                        SYSLOG::RES_CUST => $nodedata['ownerid']
                        );
                        $this->syslog->AddMessage(SYSLOG::RES_NODEASSIGN, SYSLOG::OPER_DELETE, $args);
                    }
                }
            }
            $this->db->Execute('DELETE FROM nodeassignments WHERE nodeid = ?', array($nodedata['id']));
        }
    }

    public function DeleteNode($id)
    {
        $this->db->BeginTrans();

        if ($this->syslog) {
            $customerid = $this->db->GetOne('SELECT ownerid FROM vnodes WHERE id = ?', array($id));
            $macs = $this->db->GetCol('SELECT macid FROM vmacs WHERE id = ?', array($id));
            if (!empty($macs)) {
                foreach ($macs as $mac) {
                    $args = array(
                    SYSLOG::RES_MAC => $mac,
                    SYSLOG::RES_NODE => $id,
                    SYSLOG::RES_CUST => $customerid
                    );
                    $this->syslog->AddMessage(SYSLOG::RES_MAC, SYSLOG::OPER_DELETE, $args);
                }
            }
            $args = array(
                SYSLOG::RES_NODE => $id,
                SYSLOG::RES_CUST => $customerid
            );
            $this->syslog->AddMessage(SYSLOG::RES_NODE, SYSLOG::OPER_DELETE, $args);
        }

        $this->db->Execute('DELETE FROM nodes WHERE id = ?', array($id));
        $this->db->Execute('DELETE FROM nodegroupassignments WHERE nodeid = ?', array($id));
        $this->db->CommitTrans();
    }

    public function GetNodeNameByMAC($mac)
    {
        return $this->db->GetOne('SELECT name FROM vnodes WHERE mac=UPPER(?)', array($mac));
    }

    public function GetNodeIDByIP($ipaddr)
    {
        return $this->db->GetOne('SELECT id FROM vnodes WHERE ipaddr=inet_aton(?) OR ipaddr_pub=inet_aton(?)', array($ipaddr, $ipaddr));
    }

    public function GetNodeIDByMAC($mac)
    {
        return $this->db->GetOne('SELECT nodeid FROM macs WHERE mac=UPPER(?)', array($mac));
    }

    public function GetNodeIDByName($name)
    {
        return $this->db->GetOne('SELECT id FROM vnodes WHERE UPPER(name)=UPPER(?)', array($name));
    }

    public function GetNodeIDByLogin($login)
    {
        return $this->db->GetOne('SELECT id FROM vnodes WHERE UPPER(login) = UPPER(?)', array($login));
    }

    public function GetNodeIDByNetName($name)
    {
        return $this->db->GetOne(
            'SELECT id FROM nodes WHERE ipaddr = 0 AND ipaddr_pub = 0 AND UPPER(name)=UPPER(?)',
            array($name)
        );
    }

    public function GetNodeIPByID($id)
    {
        return $this->db->GetOne('SELECT inet_ntoa(ipaddr) FROM vnodes WHERE id=?', array($id));
    }

    public function GetNodePubIPByID($id)
    {
        return $this->db->GetOne('SELECT inet_ntoa(ipaddr_pub) FROM vnodes WHERE id=?', array($id));
    }

    public function GetNodeMACByID($id)
    {
        return $this->db->GetOne('SELECT mac FROM vnodes WHERE id=?', array($id));
    }

    public function GetNodeName($id)
    {
        return $this->db->GetOne('SELECT name FROM vnodes WHERE id=?', array($id));
    }

    public function GetNodeNameByIP($ipaddr)
    {
        return $this->db->GetOne('SELECT name FROM vnodes WHERE ipaddr=inet_aton(?) OR ipaddr_pub=inet_aton(?)', array($ipaddr, $ipaddr));
    }
    public function GetNodeConnType($id)
    {
        return $this->db->GetOne('SELECT authtype FROM vnodes WHERE id=?', array($id));
    }

    public function GetNode($id)
    {
        if ($result = $this->db->GetRow('SELECT n.*, COALESCE(n.netdev, 0) AS netdev, rs.name AS linkradiosectorname,
				inet_ntoa(n.ipaddr) AS ip, inet_ntoa(n.ipaddr_pub) AS ip_pub,
				lc.name AS city_name,
				(CASE WHEN ls.name2 IS NOT NULL THEN ' . $this->db->Concat('ls.name2', "' '", 'ls.name') . ' ELSE ls.name END) AS street_name,
				(CASE WHEN addr.city_id IS NOT NULL THEN 1 ELSE 0 END) AS teryt,
				lt.name AS street_type,
				lb.name AS borough_name, lb.type AS borough_type,
				ld.name AS district_name, lst.name AS state_name,
				addr.name as location_name,
				addr.state as location_state_name, addr.state_id as location_state,
				addr.zip as location_zip, addr.country_id as location_country,
				addr.city as location_city_name, addr.street as location_street_name,
				addr.city_id as location_city, addr.street_id as location_street,
				addr.house as location_house, addr.flat as location_flat
			FROM vnodes n
				LEFT JOIN addresses addr           ON addr.id = n.address_id
				LEFT JOIN netradiosectors rs       ON rs.id = n.linkradiosector
				LEFT JOIN location_cities lc       ON (lc.id = addr.city_id)
				LEFT JOIN location_streets ls      ON (ls.id = addr.street_id)
				LEFT JOIN location_street_types lt ON (lt.id = ls.typeid)
				LEFT JOIN location_boroughs lb     ON (lb.id = lc.boroughid)
				LEFT JOIN location_districts ld    ON (ld.id = lb.districtid)
				LEFT JOIN location_states lst      ON (lst.id = ld.stateid)
			WHERE n.id = ?', array($id))
        ) {
            $customer_manager = new LMSCustomerManager($this->db, $this->auth, $this->cache, $this->syslog);
            $user_manager = new LMSUserManager($this->db, $this->auth, $this->cache, $this->syslog);
            $result['radiosectors'] = $this->db->GetAll('SELECT * FROM netradiosectors WHERE netdev = ?', array($result['netdev']));
            $result['owner'] = $customer_manager->GetCustomerName($result['ownerid']);
            $result['createdby'] = $user_manager->GetUserName($result['creatorid']);
            $result['modifiedby'] = $user_manager->GetUserName($result['modid']);
            $result['creationdateh'] = date('Y/m/d, H:i', $result['creationdate']);
            $result['moddateh'] = date('Y/m/d, H:i', $result['moddate']);
            $result['lastonlinedate'] = lastonline_date($result['lastonline']);

            // if location is empty and owner is set then heirdom address from owner
            if (!$result['location'] && $result['ownerid']) {
                global $LMS;

                $result['location'] = $LMS->getAddressForCustomerStuff($result['ownerid']);
            }

            $result['mac'] = preg_split('/,/', $result['mac']);
            foreach ($result['mac'] as $mac) {
                $result['macs'][] = array('mac' => $mac, 'producer' => get_producer($mac));
            }
            unset($result['mac']);

            if ($netname = $this->db->GetOne('SELECT name FROM networks
                    WHERE id = ?', array($result['netid']))) {
                $result['netname'] = $netname;
            }

            if ($result['ip_pub'] != '0.0.0.0') {
                $network_manager = new LMSNetworkManager($this->db, $this->auth, $this->cache, $this->syslog);
                $result['pubnetid'] = $network_manager->GetNetIDByIP($result['ip_pub']);
                $result['pubnetname'] = $this->db->GetOne('SELECT name FROM networks
					WHERE id = ?', array($result['pubnetid']));
            }

            return $result;
        } else {
            return false;
        }
    }

    /**
     * @param array $params associative array of parameters described below:
     *      status - node statuses (default: null = any), single integer value; allowed values:
     *          1 = connected,
     *          2 = disconnected,
     *          3 = online,
     *          4 = without tariff,
     *          5 = without explicit TERYT,
     *          6 = not connected to any network device,
     *          7 = with warning,
     *          8 = without GPS coords,
     *          9 = without radio sector (if wireless link),
     *          10 = with locks,
     *          11 = without TERYT,
     *          12 = offline,
     *      network - network id (default: null = any), single integer value
     *      customergroup - customer group id (default: null = any), single integer value
     *      nodegroup - node group id (default: null = any), single integer value, -1 means nodes without any group
     *      search - additional attributes (default: null = none), associative array with some well-known
     *          properties:
     *              ipaddr - ip address or public ip address (default: null = any), text value,
     *              login - node login (default: null = any), text value,
     *              state - state id (default: null = any), single integer value,
     *              district - district id (default: null = any), single integer value,
     *              borough - borough id (default: null = any), single integer value,
     *              netdev - network device (default: null = any), mixed value:
     *                  ip address or device name,
     *              project - project id (default: null = any), special values are supported:
     *                  -2 = without project,
     *                  -1 = project id is ignored,
     *              createdfrom - node created after this date (default: null = ignore),
     *              createdto - node created before this date (default: null = ignore),
     *              lastonlinebefore - last online earlier than (default: null = ignore), single integer value,
     *              lastonlineafter - last online later than (default: null = ignore), single integer value,
     *              address-origin - check node address origin (default: empty = ignore):
     *                  1 - with explicit address,
     *                  2 - with implicit address,
     *      sqlskey - sql field operator (default: 'AND') - text value; used on some fields (not all);
     *          allowed values:
     *          'AND', 'OR'
     *      count - count records only or return selected record interval
     *          true - count only,
     *          false - get records,
     *      offset - first returned record (null = 0),
     *      limit - returned record count (null = unlimited),
     *      order - returned records order (default: name,asc)
     *          can contain field_name,order pairs,
     *          supported field names:
     *          name, id, mac, ip, ip_pub, ownerid, owner, location
     *          supported orders:
     *          asc = ascending, desc = descending
     * @return mixed
     */
// $order = 'name,asc', $search = NULL, $sqlskey = 'AND', $network = NULL, $status = NULL, $customergroup = NULL, $nodegroup = NULL, $limit = null, $offset = null, $count = false)
    public function GetNodeList(array $params = array())
    {
        extract($params);

        if (empty($order)) {
            $order = 'name,asc';
        }

        [$order, $direction] = sscanf($order, '%[^,],%s');

        ($direction == 'desc') ? $direction = 'desc' : $direction = 'asc';

        switch ($order) {
            case 'name':
                $sqlord = ' ORDER BY n.name';
                break;
            case 'id':
                $sqlord = ' ORDER BY n.id';
                break;
            case 'mac':
                $sqlord = ' ORDER BY n.mac';
                break;
            case 'ip':
                $sqlord = ' ORDER BY n.ipaddr';
                break;
            case 'ip_pub':
                $sqlord = ' ORDER BY n.ipaddr_pub';
                break;
            case 'ownerid':
                $sqlord = ' ORDER BY n.ownerid';
                break;
            case 'owner':
                $sqlord = ' ORDER BY owner';
                break;
            case 'location':
                $sqlord = ' ORDER BY location';
                break;
        }

        $searchargs = array();
        if (!empty($search)) {
            foreach ($search as $key => $value) {
                if ($value != '') {
                    switch ($key) {
                        case 'ipaddr':
                            $searchargs[] = '(inet_ntoa(n.ipaddr) ?LIKE? ' . $this->db->Escape('%' . trim($value) . '%')
                            . ' OR inet_ntoa(n.ipaddr_pub) ?LIKE? ' . $this->db->Escape('%' . trim($value) . '%') . ')';
                            break;
                        case 'ip':
                            $searchargs[] = 'inet_ntoa(n.ipaddr) ?LIKE? ' . $this->db->Escape('%' . trim($value) . '%');
                            break;
                        case 'login':
                            $searchargs[] = 'LOWER(n.login) ?LIKE? ' . $this->db->Escape('%' . trim($value) . '%');
                            break;
                        case 'public_ip':
                            $searchargs[] =  'inet_ntoa(n.ipaddr_pub) ?LIKE? ' . $this->db->Escape('%' . trim($value) . '%');
                            break;
                        case 'location':
                            $searchargs[] =  'LOWER(n.location) ?LIKE? ' . $this->db->Escape('%' . trim($value) . '%');
                            break;
                        case 'state':
                            if (!empty($value)) {
                                $searchargs[] = 'n.location_city IN (SELECT lc.id FROM location_cities lc
									JOIN location_boroughs lb ON lb.id = lc.boroughid
									JOIN location_districts ld ON ld.id = lb.districtid
									JOIN location_states ls ON ls.id = ld.stateid WHERE ls.id = ' . $this->db->Escape($value) . ')';
                            }
                            break;
                        case 'district':
                            if (!empty($value)) {
                                $searchargs[] = 'n.location_city IN (SELECT lc.id FROM location_cities lc
									JOIN location_boroughs lb ON lb.id = lc.boroughid
									JOIN location_districts ld ON ld.id = lb.districtid WHERE ld.id = ' . $this->db->Escape($value) . ')';
                            }
                            break;
                        case 'borough':
                            if (!empty($value)) {
                                $searchargs[] = 'n.location_city IN (SELECT lc.id FROM location_cities lc WHERE lc.boroughid = '
                                . $this->db->Escape($value) . ')';
                            }
                            break;
                        case 'project':
                            $project = intval($value);
                            if ($project) {
                                switch ($project) {
                                    case -2:
                                        $searchargs[] = 'n.invprojectid IS NULL';
                                        break;
                                    case -1:
                                        break;
                                    default:
                                        $searchargs[] = 'n.invprojectid = ' . $project;
                                        break;
                                }
                            }
                            break;
                        case 'netdev':
                            if (check_ip($value)) {
                                $searchargs[] = 'n.netdev IN (
										SELECT nd.id FROM netdevices nd
										JOIN nodes n2 ON n2.netdev = nd.id AND n2.ownerid IS NULL
										WHERE INET_NTOA(n2.ipaddr) = ' . $this->db->Escape($value) . '
									)';
                            } else {
                                $searchargs[] = 'n.netdev IN (
										SELECT nd.id FROM netdevices nd
										WHERE LOWER(nd.name) ?LIKE? ' . $this->db->Escape("%$value%") . '
									)';
                            }
                            break;
                        case 'createdfrom':
                            $searchargs[] = 'n.creationdate >= ' . intval($value);
                            break;
                        case 'createdto':
                            $searchargs[] = 'n.creationdate <= ' . intval($value);
                            break;
                        case 'lastonlinebefore':
                            $searchargs[] = 'n.lastonline <= ' . intval($value);
                            break;
                        case 'lastonlineafter':
                            $searchargs[] = 'n.lastonline >= ' . intval($value);
                            break;
                        case 'address-origin':
                            switch (intval($value)) {
                                case 1:
                                    $searchargs[] = 'n.address_id IS NOT NULL';
                                    break;
                                case 2:
                                    $searchargs[] = 'n.address_id IS NULL';
                                    break;
                            }
                            break;
                        default:
                            $searchargs[] = 'n.' . $key . ' ?LIKE? ' . $this->db->Escape("%$value%");
                    }
                }
            }
        }

        if (!empty($searchargs)) {
            $searchargs = ' AND (' . implode(' ' . $sqlskey . ' ', $searchargs) . ')';
        }

        $totalon = 0;
        $totaloff = 0;

        if (isset($network) && $network) {
            $network_manager = new LMSNetworkManager($this->db, $this->auth, $this->cache, $this->syslog);
            $net = $network_manager->GetNetworkParams($network);
        } else {
            $net = null;
        }

        if (!isset($status)) {
            $status = null;
        }

        $sql = '';

        if (!isset($count)) {
            $count = false;
        }

        if ($count) {
            $sql .= 'SELECT COUNT(n.id) ';
        } else {
            $daysecond = time() - strtotime('today');
            $weekday = 1 << (date('N') - 1);
            $sql .= 'SELECT n.id AS id, n.ipaddr, inet_ntoa(n.ipaddr) AS ip, ipaddr_pub,
				inet_ntoa(n.ipaddr_pub) AS ip_pub, n.mac, n.name, n.ownerid, n.access, n.warning,
				n.netdev, n.lastonline, n.info, n.longitude, n.latitude, n.linktype, n.linktechnology, n.linkspeed,
				(CASE WHEN n.invprojectid = ' . INV_PROJECT_SYSTEM . ' THEN
						(CASE WHEN nd.invprojectid = ' . INV_PROJECT_SYSTEM . ' THEN pnn.name ELSE pnd.name END)
					ELSE p.name END) AS project,
				nd.netnodeid AS netnodeid, '
                . $this->db->Concat('c.lastname', "' '", 'c.name') . ' AS owner, net.name AS netname, n.location,
				lc.name AS city_name,
				lc.ident AS city_ident,
				lb.name AS borough_name,
				lb.ident AS borough_ident,
				lb.type AS borough_type,
				ld.name AS district_name,
				ld.ident AS district_ident,
				ls.name AS state_name,
				ls.ident AS state_ident,
				lst.name AS street_name,
				(CASE WHEN lst.ident IS NULL
					THEN (CASE WHEN c.street = \'\' THEN \'99999\' ELSE \'99998\' END)
					ELSE lst.ident END) AS street_ident,
				n.location_house,
				n.location_flat,
				a.zip,
				c.type AS ctype,
				(CASE WHEN EXISTS (
                    SELECT 1 FROM nodelocks
                    WHERE disabled = 0 AND (days & ' . $weekday . ') > 0 AND ' . $daysecond . ' >= fromsec
                        AND ' . $daysecond . ' <= tosec AND nodeid = n.id
                ) THEN 1 ELSE 0 END) AS locked ';
        }

        $sql .= 'FROM vnodes n
                LEFT JOIN addresses a ON a.id = n.address_id
				JOIN customerview c ON (n.ownerid = c.id)
				JOIN networks net ON net.id = n.netid
                ' . ($status == 11
                    ? ' LEFT JOIN (
                        SELECT
                            ca2.customer_id,
                            MAX(ca2.address_id) AS address_id
                        FROM customer_addresses ca2
                        JOIN (
                            SELECT
                                ca.customer_id,
                                MAX(ca.type) AS type
                            FROM customer_addresses ca
                            JOIN vaddresses va2 ON va2.id = ca.address_id AND va2.house <> \'\'
                            WHERE ca.type > 0
                            GROUP BY ca.customer_id
                        ) ca3 ON ca2.customer_id = ca3.customer_id AND ca3.type = ca2.type
                        JOIN vaddresses va3 ON va3.id = ca2.address_id
                        WHERE va3.house <> \'\'
                        GROUP BY ca2.customer_id
                    ) ca4 ON ca4.customer_id = n.ownerid
                    LEFT JOIN addresses a4 ON a4.id = ca4.address_id'
                    : '') . '
				LEFT JOIN netdevices nd ON nd.id = n.netdev
				LEFT JOIN netnodes nn ON nn.id = nd.netnodeid
				LEFT JOIN invprojects p ON p.id = n.invprojectid
				LEFT JOIN invprojects pnd ON pnd.id = nd.invprojectid
				LEFT JOIN invprojects pnn ON pnn.id = nn.invprojectid
				LEFT JOIN location_streets lst ON lst.id = n.location_street
				LEFT JOIN location_cities lc ON lc.id = n.location_city
				LEFT JOIN location_boroughs lb ON lb.id = lc.boroughid
				LEFT JOIN location_districts ld ON ld.id = lb.districtid
				LEFT JOIN location_states ls ON ls.id = ld.stateid '
                . (!empty($customergroup) ? 'JOIN vcustomerassignments ON (vcustomerassignments.customerid = c.id) ' : '')
                . (isset($nodegroup) && $nodegroup ? ($nodegroup > 0 ? '' : 'LEFT ') . 'JOIN nodegroupassignments ON (nodeid = n.id) ' : '')
                . ' WHERE 1=1 '
                . (isset($network) && $network ? ' AND (n.netid = ' . $network . ' OR (n.ipaddr_pub > ' . $net['address'] . ' AND n.ipaddr_pub < ' . $net['broadcast'] . '))' : '')
                . ($status == 1 ? ' AND n.access = 1' : '') //connected
                . ($status == 2 ? ' AND n.access = 0' : '') //disconnected
                . ($status == 3 ? ' AND n.lastonline > ?NOW? - ' . intval(ConfigHelper::getConfig('phpui.lastonline_limit')) : '') //online
                . ($status == 12 ? ' AND n.lastonline < ?NOW? - ' . intval(ConfigHelper::getConfig('phpui.lastonline_limit')) : '') //offline
                . ($status == 4 ? ' AND n.id NOT IN (
					SELECT DISTINCT nodeid FROM nodeassignments na
					JOIN assignments a ON a.id = na.assignmentid
					WHERE a.suspended = 0 AND a.commited = 1 AND a.period IN (' . implode(',', array(YEARLY, HALFYEARLY, QUARTERLY, MONTHLY, DISPOSABLE)) . ')
						AND a.datefrom <= ?NOW? AND (a.dateto = 0 OR a.dateto >= ?NOW?)
					)' : '')
                . ($status == 5 ? ' AND n.location_city IS NULL' : '')
                . ($status == 11 ? ' AND (n.location_city IS NULL AND (a4.id IS NULL OR a4.city_id IS NULL))' : '')
                . ($status == 6 ? ' AND n.netdev IS NULL' : '')
                . ($status == 7 ? ' AND n.warning = 1' : '')
                . ($status == 8 ? ' AND (n.latitude IS NULL OR n.longitude IS NULL)' : '')
                . ($status == 9 ? ' AND (n.linktype = ' . LINKTYPE_WIRELESS . ' AND n.linkradiosector IS NULL)' : '')
                . ($status == 10 ? ' AND EXISTS (SELECT 1 FROM nodelocks WHERE disabled = 0 AND nodeid = n.id)' : '')
                . (!empty($customergroup) ? ' AND customergroupid = ' . intval($customergroup) : '')
                . (isset($nodegroup) ? ($nodegroup > 0 ? ' AND nodegroupid = ' . intval($nodegroup)
                    : ($nodegroup == -1 ? ' AND NOT EXISTS (SELECT 1 FROM nodegroupassignments nga WHERE nga.nodeid = n.id)' : '')) : '')
                . (!empty($searchargs) ? $searchargs : '')
                . ($sqlord != '' && !$count ? $sqlord . ' ' . $direction : '')
                . (isset($limit) && !$count ? ' LIMIT ' . $limit : '')
                . (isset($offset) && !$count ? ' OFFSET ' . $offset : '');

        if (!$count) {
            $nodelist = $this->db->GetAll($sql);

            if (!empty($nodelist)) {
                foreach ($nodelist as &$row) {
                    ($row['access']) ? $totalon++ : $totaloff++;
                    $row['lastonlinedate'] = lastonline_date($row['lastonline']);

                    // if location is empty and owner is set then heirdom address from owner
                    if (!$row['location'] && $row['ownerid']) {
                        global $LMS;

                        $row['location'] = $LMS->getAddressForCustomerStuff($row['ownerid']);
                    }

                    $row['terc'] = empty($row['state_ident']) ? null
                        : $row['state_ident'] . $row['district_ident']
                        . $row['borough_ident'] . $row['borough_type'];
                    $row['simc'] = empty($row['city_ident']) ? null : $row['city_ident'];
                    $row['ulic'] = empty($row['street_ident']) ? null : $row['street_ident'];
                }
                unset($row);

                $nodelist['total'] = count($nodelist);
                $nodelist['order'] = $order;
                $nodelist['direction'] = $direction;
                $nodelist['totalon'] = $totalon;
                $nodelist['totaloff'] = $totaloff;

                return $nodelist;
            } else {
                return array(
                    'total' => 0,
                    'order' => $order,
                    'direction' => $direction,
                    'totalon' => 0,
                    'totaloff' => 0,
                );
            }
        } else {
            return $this->db->getOne($sql);
        }
    }

    public function NodeSet($id, $access = -1)
    {
        $customerid = $this->db->GetOne('SELECT ownerid FROM vnodes WHERE id = ?', array($id));
        $args = array(
            SYSLOG::RES_NODE => $id,
            SYSLOG::RES_CUST => $customerid
        );

        if ($access != -1) {
            $args['access'] = $access;
            if ($access) {
                if ($this->db->GetOne('SELECT 1 FROM vnodes WHERE id = ? AND EXISTS
					(SELECT 1 FROM customers WHERE id = ownerid AND status = 3)', array($id))) {
                    if ($this->syslog) {
                        $this->syslog->AddMessage(SYSLOG::RES_NODE, SYSLOG::OPER_UPDATE, $args);
                    }
                    return $this->db->Execute('UPDATE nodes SET access = 1 WHERE id = ?
						AND EXISTS (SELECT 1 FROM customers WHERE id = ownerid
							AND status = 3)', array($id));
                }
                return 0;
            } else {
                if ($this->syslog) {
                    $this->syslog->AddMessage(SYSLOG::RES_NODE, SYSLOG::OPER_UPDATE, $args);
                }
                return $this->db->Execute('UPDATE nodes SET access = 0 WHERE id = ?', array($id));
            }
        } elseif ($this->db->GetOne('SELECT access FROM vnodes WHERE id = ?', array($id)) == 1) {
            if ($this->syslog) {
                $args['access'] = 0;
                $this->syslog->AddMessage(SYSLOG::RES_NODE, SYSLOG::OPER_UPDATE, $args);
            }
            return $this->db->Execute('UPDATE nodes SET access=0 WHERE id = ?', array($id));
        } else {
            if ($this->db->GetOne('SELECT 1 FROM vnodes WHERE id = ? AND EXISTS
				(SELECT 1 FROM customers WHERE id = ownerid AND status = 3)', array($id))) {
                if ($this->syslog) {
                    $args['access'] = 1;
                    $this->syslog->AddMessage(SYSLOG::RES_NODE, SYSLOG::OPER_UPDATE, $args);
                }
                return $this->db->Execute('UPDATE nodes SET access = 1 WHERE id = ?
						AND EXISTS (SELECT 1 FROM customers WHERE id = ownerid
							AND status = 3)', array($id));
            }
            return 0;
        }
    }

    public function NodeSetU($id, $access = false)
    {
        if ($access) {
            if ($this->db->GetOne('SELECT status FROM customers WHERE id = ?', array($id)) == 3) {
                if ($this->syslog) {
                    $nodes = $this->db->GetCol('SELECT id FROM vnodes WHERE ownerid = ?', array($id));
                    $args = array(
                        SYSLOG::RES_CUST => $id,
                        'access' => $access
                    );
                    if (!empty($nodes)) {
                        foreach ($nodes as $nodeid) {
                            $args[SYSLOG::RES_NODE] = $nodeid;
                            $this->syslog->AddMessage(SYSLOG::RES_NODE, SYSLOG::OPER_UPDATE, $args);
                        }
                    }
                }
                return $this->db->Execute('UPDATE nodes SET access=1 WHERE ownerid=?', array($id));
            }
        } else {
            if ($this->syslog) {
                $nodes = $this->db->GetCol('SELECT id FROM vnodes WHERE ownerid = ?', array($id));
                $args = array(
                    SYSLOG::RES_CUST => $id,
                    'access' => $access
                );
                if (!empty($nodes)) {
                    foreach ($nodes as $nodeid) {
                        $args[SYSLOG::RES_NODE] = $nodeid;
                        $this->syslog->AddMessage(SYSLOG::RES_NODE, SYSLOG::OPER_UPDATE, $args);
                    }
                }
            }
            return $this->db->Execute('UPDATE nodes SET access=0 WHERE ownerid=?', array($id));
        }
    }

    public function NodeSetWarn($id, $warning = false)
    {
        if ($this->syslog) {
            $cids = $this->db->GetAll('SELECT id, ownerid FROM vnodes WHERE id IN ('
                    . (is_array($id) ? implode(',', $id) : $id) . ')');
            if (!empty($cids)) {
                foreach ($cids as $cid) {
                    $args = array(
                    SYSLOG::RES_NODE => $cid['id'],
                    SYSLOG::RES_CUST => $cid['ownerid'],
                    'warning' => $warning
                    );
                    $this->syslog->AddMessage(SYSLOG::RES_NODE, SYSLOG::OPER_UPDATE, $args);
                }
            }
        }
        return $this->db->Execute('UPDATE nodes SET warning = ? WHERE id IN ('
                        . (is_array($id) ? implode(',', $id) : $id) . ')', array($warning ? 1 : 0));
    }

    public function NodeSwitchWarn($id)
    {
        if ($this->syslog) {
            $node = $this->db->GetRow('SELECT ownerid, warning FROM vnodes WHERE id = ?', array($id));
            $args = array(
                SYSLOG::RES_NODE => $id,
                SYSLOG::RES_CUST => $node['ownerid'],
                'warning' => ($node['warning'] ? 0 : 1)
            );
            $this->syslog->AddMessage(SYSLOG::RES_NODE, SYSLOG::OPER_UPDATE, $args);
        }
        return $this->db->Execute('UPDATE nodes
			SET warning = (CASE warning WHEN 0 THEN 1 ELSE 0 END)
			WHERE id = ?', array($id));
    }

    public function NodeSetWarnU($id, $warning = false)
    {
        if ($this->syslog) {
            $nodes = $this->db->GetAll('SELECT id, ownerid FROM vnodes WHERE ownerid IN ('
                    . (is_array($id) ? implode(',', $id) : $id) . ')');
            if (!empty($nodes)) {
                foreach ($nodes as $node) {
                    $args = array(
                        SYSLOG::RES_NODE => $node['id'],
                        SYSLOG::RES_CUST => $node['ownerid'],
                        'warning' => $warning ? 1 : 0
                    );
                    $this->syslog->AddMessage(SYSLOG::RES_NODE, SYSLOG::OPER_UPDATE, $args);
                }
            }
        }
        return $this->db->Execute('UPDATE nodes SET warning = ? WHERE ownerid IN ('
                        . (is_array($id) ? implode(',', $id) : $id) . ')', array($warning ? 1 : 0));
    }

    public function IPSetU($netdev, $access = false)
    {
        if ($access) {
            $res = $this->db->Execute('UPDATE nodes SET access=1 WHERE netdev=? AND ownerid IS NULL', array($netdev));
        } else {
            $res = $this->db->Execute('UPDATE nodes SET access=0 WHERE netdev=? AND ownerid IS NULL', array($netdev));
        }
        if ($this->syslog && $res) {
            $nodes = $this->db->GetCol('SELECT id FROM vnodes WHERE netdev=? AND ownerid IS NULL', array($netdev));
            foreach ($nodes as $node) {
                $args = array(
                    SYSLOG::RES_NODE => $node,
                    SYSLOG::RES_NETDEV => $netdev,
                    'access' => intval($access),
                );
                $this->syslog->AddMessage(SYSLOG::RES_NODE, SYSLOG::OPER_UPDATE, $args);
            }
        }
        return $res;
    }

    public function NodeAdd($nodedata)
    {
        static $node_empty_mac = null;

        $args = array(
            'name'              => ConfigHelper::checkConfig('nodes.capitalize_names', ConfigHelper::checkConfig('phpui.capitalize_node_names', true))
                ? strtoupper($nodedata['name']) : $nodedata['name'],
            'ipaddr'            => $nodedata['ipaddr'],
            'ipaddr_pub'        => $nodedata['ipaddr_pub'],
            'login'             => empty($nodedata['login']) ? null : $nodedata['login'],
            SYSLOG::RES_CUST    => empty($nodedata['ownerid']) ? null : $nodedata['ownerid'],
            'passwd'            => $nodedata['passwd'],
            SYSLOG::RES_USER    => Auth::GetCurrentUser(),
            'access'            => $nodedata['access'],
            'warning'           => $nodedata['warning'],
            'info'              => Utils::removeInsecureHtml($nodedata['info']),
            SYSLOG::RES_NETDEV  => empty($nodedata['netdev']) ? null : $nodedata['netdev'],
            'linktype'          => isset($nodedata['linktype']) && (is_int($nodedata['linktype']) || ctype_digit($nodedata['linktype']))
                ? intval($nodedata['linktype']) : null,
            'linkradiosector'   => isset($nodedata['linktype']) && (is_int($nodedata['linktype']) || ctype_digit($nodedata['linktype']))
                && intval($nodedata['linktype']) == LINKTYPE_WIRELESS
                && !empty($nodedata['radiosector']) && (is_int($nodedata['radiosector']) || ctype_digit($nodedata['radiosector']))
                ? intval($nodedata['radiosector']) : null,
            'linktechnology'    => !empty($nodedata['linktechnology']) && (is_int($nodedata['linktechnology']) || ctype_digit($nodedata['linktechnology']))
                ? intval($nodedata['linktechnology']) : null,
            'linkspeed'         => !empty($nodedata['linkspeed']) && (is_int($nodedata['linkspeed']) || ctype_digit($nodedata['linkspeed']))
                ? intval($nodedata['linkspeed']) : null,
            'port'              => isset($nodedata['port']) && $nodedata['netdev'] ? intval($nodedata['port']) : 0,
            'chkmac'            => $nodedata['chkmac'],
            'halfduplex'        => $nodedata['halfduplex'],
            'nas'               => $nodedata['nas'] ?? 0,
            'longitude'         => !empty($nodedata['longitude']) ? str_replace(',', '.', $nodedata['longitude']) : null,
            'latitude'          => !empty($nodedata['latitude'])  ? str_replace(',', '.', $nodedata['latitude'])  : null,
            SYSLOG::RES_NETWORK => $nodedata['netid'],
            'invprojectid'      => isset($nodedata['invprojectid']) ? intval($nodedata['invprojectid']) : null,
            'authtype'          => $nodedata['authtype'],
            'address_id'        => !empty($nodedata['address_id']) && $nodedata['address_id'] > 0 ? $nodedata['address_id'] : null,
        );

        if ($this->db->Execute('INSERT INTO nodes (name, ipaddr, ipaddr_pub, login, ownerid,
			passwd, creatorid, creationdate, access, warning, info, netdev,
			linktype, linkradiosector, linktechnology,
			linkspeed, port, chkmac, halfduplex, nas,
			longitude, latitude, netid, invprojectid, authtype, address_id)
			VALUES (?, inet_aton(?), inet_aton(?), ?, ?, ?, ?,
			?NOW?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_values($args))) {
            $id = $this->db->GetLastInsertID('nodes');

            // EtherWerX support (devices have some limits)
            // We must to replace big ID with smaller (first free)
            if ($id > 99999 && ConfigHelper::checkConfig('phpui.ewx_support')) {
                $this->db->BeginTrans();
                $this->db->LockTables('nodes');

                if ($newid = $this->db->GetOne('SELECT n.id + 1 FROM vnodes n
						LEFT OUTER JOIN vnodes n2 ON n.id + 1 = n2.id
						WHERE n2.id IS NULL AND n.id <= 99999
						ORDER BY n.id ASC LIMIT 1')) {
                    $this->db->Execute('UPDATE nodes SET id = ? WHERE id = ?', array($newid, $id));
                    $id = $newid;
                }

                $this->db->UnLockTables();
                $this->db->CommitTrans();
            }

            if ($this->syslog) {
                unset($args[SYSLOG::RES_USER]);
                $args[SYSLOG::RES_NODE] = $id;
                $this->syslog->AddMessage(SYSLOG::RES_NODE, SYSLOG::OPER_ADD, $args);
            }

            if (!empty($nodedata['macs'])) {
                foreach ($nodedata['macs'] as $mac) {
                    $this->db->Execute('INSERT INTO macs (mac, nodeid) VALUES(?, ?)', array(strtoupper($mac), $id));
                }

                if ($this->syslog) {
                    $macs = $this->db->GetAll('SELECT id, mac FROM macs WHERE nodeid = ?', array($id));
                    foreach ($macs as $mac) {
                        $args = array(
                            SYSLOG::RES_MAC => $mac['id'],
                            SYSLOG::RES_NODE => $id,
                            SYSLOG::RES_CUST => $nodedata['ownerid'],
                            'mac' => $mac['mac']
                        );
                        $this->syslog->AddMessage(SYSLOG::RES_MAC, SYSLOG::OPER_ADD, $args);
                    }
                }
            } else {
                if (!isset($node_empty_mac)) {
                    $node_empty_mac = ConfigHelper::getConfig('nodes.empty_mac', ConfigHelper::getConfig('phpui.node_empty_mac', '', true));
                    if (strlen($node_empty_mac) && check_mac($node_empty_mac)) {
                        $node_empty_mac = Utils::normalizeMac($node_empty_mac);
                    }
                }

                if (strlen($node_empty_mac)) {
                    $this->db->Execute(
                        'INSERT INTO macs (mac, nodeid) VALUES (?, ?)',
                        array(
                            $node_empty_mac,
                            $id
                        )
                    );
                }
            }

            return $id;
        }

        return false;
    }

    public function NodeExists($id)
    {
        return (bool)$this->db->GetOne(
            'SELECT n.id FROM vnodes n
			WHERE n.id = ? AND n.ownerid IS NOT NULL AND NOT EXISTS (
		        	SELECT 1 FROM vcustomerassignments a
			        JOIN excludedgroups e ON (a.customergroupid = e.customergroupid)
				WHERE e.userid = lms_current_user() AND a.customerid = n.ownerid)',
            array($id)
        );
    }

    public function NodeStats()
    {
        $result = $this->db->GetRow(
            'SELECT COUNT(CASE WHEN access = 1 THEN 1 END) AS connected,
                COUNT(CASE WHEN access = 0 THEN 1 END) AS disconnected,
                COUNT(CASE WHEN ?NOW?-lastonline < ? THEN 1 END) AS online,
                COUNT(CASE WHEN location_city IS NULL THEN 1 END) AS withoutexplicitteryt,
                COUNT(CASE WHEN location_city IS NULL AND (a4.id IS NULL OR a4.city_id IS NULL) THEN 1 END) AS withoutteryt,
                COUNT(CASE WHEN netdev IS NULL THEN 1 END) AS withoutnetdev,
                COUNT(CASE WHEN warning = 1 THEN 1 END) AS withwarning
            FROM vnodes
            JOIN customerview c ON c.id = ownerid
            LEFT JOIN (
                SELECT
                    ca2.customer_id,
                    MAX(ca2.address_id) AS address_id
                FROM customer_addresses ca2
                JOIN (
                    SELECT
                        ca.customer_id,
                        MAX(ca.type) AS type
                    FROM customer_addresses ca
                    JOIN vaddresses va2 ON va2.id = ca.address_id AND va2.house <> \'\'
                    WHERE ca.type > 0
                    GROUP BY ca.customer_id
                ) ca3 ON ca2.customer_id = ca3.customer_id AND ca3.type = ca2.type
                JOIN vaddresses va3 ON va3.id = ca2.address_id
                WHERE va3.house <> \'\'
                GROUP BY ca2.customer_id
            ) ca4 ON ca4.customer_id = c.id
            LEFT JOIN addresses a4 ON a4.id = ca4.address_id
            WHERE ownerid IS NOT NULL',
            array(
                ConfigHelper::getConfig('phpui.lastonline_limit'),
            )
        );

        $result['total'] = $result['connected'] + $result['disconnected'];
        return $result;
    }

    public function GetNodeLinkType($devid, $nodeid)
    {
        $link = $this->db->GetRow(
            'SELECT linktype AS type, linktechnology AS technology,
            linkspeed AS speed, linkradiosector AS radiosector, port FROM nodes
            WHERE netdev = ? AND id = ?',
            array($devid, $nodeid)
        );
        if (empty($link)) {
            $link = array();
        } else {
            $link['radiosectors'] = $this->db->GetAll(
                'SELECT id, name FROM netradiosectors WHERE netdev = ?'
                . (!empty($link['technology']) ? ' AND (technology = ' . $link['technology'] . ' OR technology IS NULL)' : '')
                . ' ORDER BY name',
                array($devid)
            );
        }

        return $link;
    }

    public function ValidateNodeLink($node, $link)
    {
        $netdev = $this->db->GetOne('SELECT netdev FROM nodes WHERE id  = ?', array($node));
        if (!$netdev) {
            return trans('Unknown error!');
        }

        if ($this->db->GetOne(
            'SELECT id
            FROM netlinks
            WHERE (src = ? AND srcport = ?) OR (dst = ? AND dstport = ?)',
            array($netdev, $link['port'], $netdev, $link['port'])
        ) || $this->db->GetOne(
            'SELECT id
            FROM nodes
            WHERE port = ? AND id <> ?',
            array($link['port'], $node)
        )) {
            return trans('Selected port number is taken by other device or node!');
        }

        if ($this->db->GetOne('SELECT ports FROM netdevices WHERE id = ?', array($netdev)) < intval($link['port'])) {
            return trans('Incorrect port number!');
        }

        return true;
    }

    public function SetNodeLinkType($node, $link = null)
    {
        if (empty($link)) {
            $type = LINKTYPE_WIRE;
            $technology = null;
            $radiosector = null;
            $speed = null;
        } else {
            $type = isset($link['type']) && ctype_digit($link['type']) ? intval($link['type']) : null;
            $radiosector = !empty($link['radiosector']) && (is_int($link['radiosector']) || ctype_digit($link['radiosector']))
                ? intval($link['radiosector']) : null;
            if ($type != LINKTYPE_WIRELESS || empty($radiosector)) {
                $radiosector = null;
            }
            $technology = !empty($link['technology']) && (is_int($link['technology']) || ctype_digit($link['technology']))
                ? intval($link['technology']) : null;
            $speed = !empty($link['speed']) && (is_int($link['speed']) || ctype_digit($link['speed']))
                ? intval($link['speed']) : null;
        }

        $query = 'UPDATE nodes SET linktype = ?, linkradiosector = ?, linktechnology = ?, linkspeed = ?';
        $args = array($type, $radiosector, $technology, $speed);
        if (isset($link['port'])) {
            $query .= ', port = ?';
            $args[] = intval($link['port']);
        }
        $query .= ' WHERE id=?';
        $args[] = $node;
        $res = $this->db->Execute($query, $args);

        if ($this->syslog && $res) {
            $nodedata = $this->db->GetRow('SELECT ownerid, netdev FROM vnodes WHERE id=?', array($node));
            $args = array(
                SYSLOG::RES_NODE => $node,
                SYSLOG::RES_CUST => $nodedata['ownerid'],
                SYSLOG::RES_NETDEV => $nodedata['netdev'],
                'linktype' => $type,
                'linkradiosector' => $radiosector,
                'linktechnology' => $technology,
                'linkspeed' => $speed,
            );
            $this->syslog->AddMessage(SYSLOG::RES_NODE, SYSLOG::OPER_UPDATE, $args);
        }
        return $res;
    }

    /*
     * \brief Update single node filed.
     *
     * \param int    $nodeid
     * \param string $field  field from database to update
     * \param mixed  $value  new value
     */
    public function updateNodeField($nodeid, $field, $value)
    {
        $nodeid    = (int) $nodeid;
        $field     = strtolower($field);
        $error_msg = 0;

        switch ($field) {
            case 'authtype':
                if (!is_numeric($value)) {
                    $error_msg = "Value isn't a number";
                } else {
                    global $SESSIONTYPES;

                    $value = (int) $value;
                    $tmp   = 0;

                    foreach ($SESSIONTYPES as $k => $v) {
                        if ($value & $k) {
                            $tmp += $k;
                        }
                    }

                    $value = $tmp;
                }
                break;

            case 'nas':
            case 'halfduplex':
            case 'chkmac':
                $value = ($value == 1) ? 1 : 0;
                break;

            default:
                $error_msg = "Unknown field name.";
        }

        $SYSLOG = SYSLOG::getInstance();

        if ($SYSLOG) {
            $args = array(
                SYSLOG::RES_NODE => $nodeid,
                'field' => $field,
                'value' => $value
            );

            $SYSLOG->AddMessage(SYSLOG::RES_NODE, SYSLOG::OPER_UPDATE, $args);
        }

        if (!$error_msg) {
            $this->db->Execute('UPDATE nodes SET ' . $field . ' = ? WHERE id = ?', array($value, $nodeid));
        } else {
            return $error_msg;
        }
    }

    public function GetUniqueNodeLocations($customerid)
    {
        $nodes = $this->db->GetAll(
            'SELECT id AS nodeid, location FROM vnodes WHERE ownerid = ?',
            array($customerid)
        );
        if (empty($nodes)) {
            return null;
        }

        $customer_manager = new LMSCustomerManager($this->db, $this->auth, $this->cache, $this->syslog);
        $locations = array();
        foreach ($nodes as $node) {
            if (empty($node['location'])) {
                if (!isset($default_location)) {
                    $default_location = $customer_manager->getAddressForCustomerStuff($customerid);
                }
                $locations[] = $default_location;
            } else {
                $locations[] = $node['location'];
            }
        }
        return array_unique($locations);
    }

    public function GetNodeLocations($customerid, $address_id = null)
    {
        $customerid = intval($customerid);

        $nodes = $this->db->GetAllByKey('SELECT n.id, n.name, location, address_id FROM vnodes n
			WHERE ownerid = ?' . (empty($address_id) ? '' : ' AND (address_id IS NULL OR address_id = ' . intval($address_id) . ')')
            . ' ORDER BY n.name ASC', 'id', array($customerid));
        if (empty($nodes)) {
            return null;
        }

        foreach ($nodes as $idx => &$node) {
            if (empty($node['address_id'])) {
                if (!isset($default_address)) {
                    $customer_manager = new LMSCustomerManager($this->db, $this->auth, $this->cache, $this->syslog);
                    $default_address = $customer_manager->getFullAddressForCustomerStuff($customerid);
                }
                if (!empty($address_id) && $address_id != $default_address['address_id']) {
                    unset($nodes[$idx]);
                    continue;
                }
                $node['address_id'] = $default_address['address_id'];
                $node['location'] = $default_address['location'];
            }
        }
        unset($node);

        return $nodes;
    }

    public function GetNodeCustomerAssignments($nodeid, $assignments)
    {
        if (empty($assignments)) {
            return $assignments;
        }

        $node_assignments = array();

        foreach ($assignments as $assignment) {
            if (!isset($assignment['nodes'])) {
                continue;
            }
            foreach ($assignment['nodes'] as $node) {
                if (empty($node['ownerid']) || $node['id'] != $nodeid) {
                    continue;
                }
                $node_assignments[$nodeid][] = $assignment;
                break;
            }
        }

        return $node_assignments;
    }

    public function getNodeRoutedNetworks($nodeid)
    {
        return $this->db->GetAllByKey(
            'SELECT n.*, rn.comment
            FROM vnetworks n
            JOIN routednetworks rn ON rn.netid = n.id
            WHERE rn.nodeid = ?',
            'id',
            array($nodeid)
        );
    }

    public function getNodeNotRoutedNetworks($nodeid)
    {
        return $this->db->GetAllByKey(
            'SELECT n.*
            FROM vnetworks n
            LEFT JOIN routednetworks rn ON rn.netid = n.id
            LEFT JOIN nodes ON nodes.ownerid = n.ownerid OR nodes.ownerid IS NULL
            LEFT JOIN netdevices nd ON nd.id = nodes.netdev AND nodes.ownerid IS NULL
            WHERE rn.id IS NULL AND nodes.id = ? AND (nodes.ownerid = n.ownerid OR nd.ownerid = n.ownerid)',
            'id',
            array($nodeid)
        );
    }

    public function addNodeRoutedNetworks(array $params)
    {
        $added = 0;

        if (!empty($params['networks'])) {
            $customerid = $this->GetNodeOwner($params['nodeid']);
            if (empty($customerid)) {
                $netdev_manager = new LMSNetDevManager($this->db, $this->auth, $this->cache, $this->syslog);
                $customerid = $netdev_manager->getNetDevOwnerByNodeId($params['nodeid']);
            }

            foreach ($params['networks'] as $network) {
                $args = array(
                    SYSLOG::RES_NETWORK => $network,
                    SYSLOG::RES_NODE => $params['nodeid'],
                    'comment' => $params['comment'],
                );
                $result = $this->db->Execute(
                    'INSERT INTO routednetworks (netid, nodeid, comment) VALUES (?, ?, ?)',
                    array_values($args)
                );
                if ($result) {
                    $added += $result;
                    if ($this->syslog) {
                        $args[SYSLOG::RES_ROUTEDNET] = $this->db->GetLastInsertID('routednetworks');
                        $args[SYSLOG::RES_CUST] = $customerid;
                        $this->syslog->AddMessage(
                            SYSLOG::RES_ROUTEDNET,
                            SYSLOG::OPER_ADD,
                            $args
                        );
                    }
                }
            }
        }

        return $added;
    }

    public function deleteNodeRoutedNetworks(array $params)
    {
        $removed = 0;

        if (!empty($params['networks'])) {
            if (!isset($params['nodeid'])) {
                $params['nodeid'] = $this->db->GetOne(
                    'SELECT nodeid FROM routednetworks WHERE netid = ?',
                    array(reset($params['networks']))
                );
            }

            $customerid = $this->GetNodeOwner($params['nodeid']);

            if (empty($customerid)) {
                $netdev_manager = new LMSNetDevManager($this->db, $this->auth, $this->cache, $this->syslog);
                $customerid = $netdev_manager->getNetDevOwnerByNodeId($params['nodeid']);
            }

            foreach ($params['networks'] as $network) {
                if ($this->syslog) {
                    $routednetid = $this->db->GetOne(
                        'SELECT id FROM routednetworks WHERE nodeid = ? AND netid = ?',
                        array($params['nodeid'], $network)
                    );
                }

                $result = $this->db->Execute(
                    'DELETE FROM routednetworks WHERE nodeid = ? AND netid = ?',
                    array($params['nodeid'], $network)
                );

                if ($result) {
                    $removed += $result;
                    if ($this->syslog) {
                        $args = array(
                            SYSLOG::RES_ROUTEDNET => $routednetid,
                            SYSLOG::RES_NETWORK => $network,
                            SYSLOG::RES_NODE => $params['nodeid'],
                            SYSLOG::RES_CUST => $customerid,
                        );
                        $this->syslog->AddMessage(SYSLOG::RES_ROUTEDNET, SYSLOG::OPER_DELETE, $args);
                    }
                }
            }
        }

        return $removed;
    }

    public function GetNodeSessions($nodeid)
    {
        $nodesessions = $this->db->GetAll(
            'SELECT
                    id,
                    INET_NTOA(ipaddr) AS ipaddr,
                    mac,
                    start,
                    stop,
                    download,
                    upload,
                    terminatecause,
                    type,
                    nasipaddr,
                    INET_NTOA(nasipaddr) AS nasip,
                    nasid,
                    location
                FROM nodesessions
                WHERE nodeid = ?
                ORDER BY stop DESC
                LIMIT ' . intval(ConfigHelper::getConfig('phpui.nodesession_limit', 10)),
            array($nodeid)
        );
        if (!empty($nodesessions)) {
            foreach ($nodesessions as &$session) {
                [$number, $unit] = setunits($session['download']);
                $session['download'] = round($number, 2) . ' ' . $unit;
                [$number, $unit] = setunits($session['upload']);
                $session['upload'] = round($number, 2) . ' ' . $unit;
                $session['duration'] = $session['stop']
                    ? ($session['stop'] - $session['start'] < 60 ? trans('shorter than minute') : uptimef($session['stop'] - $session['start']))
                    : '-';
            }
            unset($session);
        }
        return $nodesessions;
    }

    public function getRadiusParams(array $params)
    {
        if (isset($params['nodesessionid'])) {
            $result = $this->db->GetRow(
                'SELECT s.nasipaddr, INET_NTOA(s.nasipaddr) AS nasip,
                    nd.secret,
                    s.ipaddr, INET_NTOA(s.ipaddr) AS ip
                FROM nodesessions s
                LEFT JOIN nodes n ON n.ipaddr = s.nasipaddr AND n.ownerid IS NULL AND n.netdev IS NOT NULL
                LEFT JOIN netdevices nd ON nd.id = n.netdev
                WHERE s.id = ?',
                array(
                    $params['nodesessionid'],
                )
            );
            if (empty($result)) {
                return $result;
            }
            if (empty($result['secret'])) {
                $result['secret'] = ConfigHelper::getConfig('phpui.default_radius_secret');
            }
            return $result;
        } else {
            return null;
        }
    }
}
