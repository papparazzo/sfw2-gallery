<?php

/**
 *  SFW2 - SimpleFrameWork
 *
 *  Copyright (C) 2018  Stefan Paproth
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program. If not, see <http://www.gnu.org/licenses/agpl.txt>.
 *
 */

namespace SFW2\Gallery\Controller;

use SFW2\Routing\AbstractController;
use SFW2\Controllers\Widget\Obfuscator\EMail;
use SFW2\Authority\User;
use SFW2\Routing\Result\Content;

use SFW2\Routing\Resolver\ResolverException;

use SFW2\Core\Database;
use SFW2\Core\Config;

use SFW2\Validator\Ruleset;
use SFW2\Validator\Validator;
use SFW2\Validator\Validators\IsNotEmpty;

use SFW2\Gallery\Helper\GalleryHelperTrait;

use SFW2\Controllers\Controller\Helper\DateTimeHelperTrait;
use SFW2\Controllers\Controller\Helper\ImageHelperTrait;
use SFW2\Controllers\Controller\Helper\EMailHelperTrait;

class Overview extends AbstractController {

    use DateTimeHelperTrait;
    use ImageHelperTrait;
    use EMailHelperTrait;
    use GalleryHelperTrait;

    const SUMMERIES_PER_PAGE = 3;
    const DIMENSIONS = 300;

    /**
     * @var Database
     */
    protected $database;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var \SFW2\Authority\User
     */
    protected $user;

    /**
     * @var string
     */
    protected $title;

    /**
     * @var string
     */
    protected $about;

    public function __construct(int $pathId, Database $database, Config $config, User $user, string $title = '', string $about = '') {
        parent::__construct($pathId);
        $this->database = $database;
        $this->config = $config;
        $this->user = $user;
        $this->title = $title;
        $this->about = $about;
    }

    public function index($all = false) {
        unset($all);
        $content = new Content('SFW2\\Gallery\\Overview');

        $content->assign('title', $this->title);
        $content->assign('about', $this->about);
        $content->assign('modificationDate', $this->getLastModificatonDate());
        $content->assign('webmaster', (string)(new EMail($this->config->getVal('project', 'eMailWebMaster'))));

        $content->appendJSFile('Overview.handlebars.js');
        $content->appendCSSFile('lightbox.min.css');
        $content->appendJSFile('lightbox.min.js');

        return $content;
    }

    public function read($all = false) {
        $count = (int)filter_input(INPUT_GET, 'count', FILTER_VALIDATE_INT);
        $start = (int)filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);

        $count = $count ? $count : self::SUMMERIES_PER_PAGE;

        $stmt =
            "SELECT `overview`.`Id`, `overview`.`Title`, `overview`.`Description`, `overview`.`Date`, " .
            "`overview`.`FileName`, `user`.`Email`, CONCAT(`user`.`FirstName`, ' ', `user`.`LastName`) AS `Creator`, " .
            "IF(`overview`.`UserId` = '%s', '1', '0') AS `OwnEntry`, `overview`.`Names` " .
            "FROM `{TABLE_PREFIX}_overview` AS `overview` " .
            "LEFT JOIN `{TABLE_PREFIX}_user` AS `user` " .
            "ON `user`.`Id` = `overview`.`UserId` " .
            "WHERE `overview`.`PathId` = '%s' ";

        if($all) {
            $stmt .=
                "ORDER BY `overview`.`Pos` DESC " .
                "LIMIT %s, %s ";
            $rows = $this->database->select($stmt, [$this->user->getUserId(), $this->pathId, $start, $count]);
            $cnt = $this->database->selectCount('{TABLE_PREFIX}_overview', "WHERE `PathId` = '%s'", [$this->pathId]);
        } else {
            $stmt .=
                "AND `UserId` = '%s' " .
                "ORDER BY `overview`.`Pos` DESC " .
                "LIMIT %s, %s ";
            $rows = $this->database->select($stmt, [$this->user->getUserId(), $this->pathId, $this->user->getUserId(), $start, $count]);
            $cnt = $this->database->selectCount('{TABLE_PREFIX}_overview', "WHERE `PathId` = '%s' AND `UserId` = '%s'", [$this->pathId, $this->user->getUserId()]);
        }

        $content = new Content('Overview');
        $entries = [];

        foreach($rows as $row) {
            $cd = $this->getDate($row['Date']);
            $entry = [];
            $entry['id'         ] = $row['Id'];
            $entry['date'       ] = $cd;
            $entry['title'      ] = $row['Title'];
            $entry['image'      ] = $this->getImageFile($row['FileName'], false);
            $entry['preview'    ] = $this->getImageFile($row['FileName'], true);
            $entry['description'] = $row['Description'];
            $entry['names'      ] = $row['Names'];
            $entry['ownEntry'   ] = (bool)$row['OwnEntry'];

            $entries[] = $entry;
        }

        $content->assign('offset', $start + $count);
        $content->assign('hasNext', $start + $count < $cnt);
        $content->assign('entries', $entries);
        return $content;
    }

    public function delete($all = false) {
        $entryId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if($entryId === false) {
            throw new ResolverException("invalid data given", ResolverException::INVALID_DATA_GIVEN);
        }

        $stmt = "SELECT `FileName` FROM `{TABLE_PREFIX}_overview` ";
        $where = "WHERE `Id` = '%s' AND `PathId` = '%s' ";

        if(!$all) {
            $where .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
        }

        $row = $this->database->selectRow($stmt . $where, [$entryId, $this->pathId]);

        if(empty($row)) {
            throw new ResolverException("no entry <$entryId> found", ResolverException::NO_PERMISSION);
        }

        $preview = $this->getImageFile($row['FileName'], true);
        if(!unlink(ltrim($preview, DIRECTORY_SEPARATOR))) {
            throw new ResolverException("unable to delete file <$preview>", ResolverException::UNKNOWN_ERROR);
        }

        $hight = $this->getImageFile($row['FileName'], false);
        if(!unlink(ltrim($hight, DIRECTORY_SEPARATOR))) {
            throw new ResolverException("unable to delete file <$hight>", ResolverException::UNKNOWN_ERROR);
        }

        $stmt = "DELETE FROM `{TABLE_PREFIX}_newspaperarticles` " . $where;
        $this->database->delete($stmt, [$entryId, $this->pathId]);
        return new Content();
    }

    public function create() {
        $content = new Content('Overview');

        $validateOnly = filter_input(INPUT_POST, 'validateOnly', FILTER_VALIDATE_BOOLEAN);

        $rulset = new Ruleset();
        $rulset->addNewRules('title', new IsNotEmpty());
        $rulset->addNewRules('description', new IsNotEmpty());
        $rulset->addNewRules('names', new IsNotEmpty());

        $validator = new Validator($rulset);
        $values = [];

        $error = $validator->validate($_POST, $values);
        $content->assignArray($values);

        if(!$error) {
            $content->setError(true);
        }

        if($validateOnly || !$error) {
            return $content;
        }

        $folder = $this->getGalleryPath();
        $fileName = $this->addFile($folder, self::DIMENSIONS);

        $stmt =
            "INSERT INTO `{TABLE_PREFIX}_overview` " .
            "SET `Date` = NOW(), " .
            "`Title` = '%s', " .
            "`Pos` = '0', " .
            "`UserId` = '%s', " .
            "`PathId` = '%s', ".
            "`Description` = '%s', " .
            "`Names` = '%s', " .
            "`FileName` = '%s' ";

        $id = $this->database->insert(
            $stmt,
            [
                $values['title']['value'],
                $this->user->getUserId(),
                $this->pathId,
                $values['description']['value'],
                $values['names']['value'],
                $fileName
            ]
        );
        return $content;
    }

    protected function getLastModificatonDate() : string {
        $stmt =
            "SELECT `overview`.`Date` " .
            "FROM `{TABLE_PREFIX}_overview` AS `overview` " .
            "WHERE `overview`.`PathId` = '%s' " .
            "ORDER BY `overview`.`Date`";

        return (string)$this->database->selectSingle($stmt, [$this->pathId]);
    }

}
