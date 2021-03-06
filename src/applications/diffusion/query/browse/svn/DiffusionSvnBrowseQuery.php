<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

final class DiffusionSvnBrowseQuery extends DiffusionBrowseQuery {

  protected function executeQuery() {
    $drequest = $this->getRequest();
    $repository = $drequest->getRepository();

    $path = $drequest->getPath();
    $commit = $drequest->getCommit();

    $conn_r = $repository->establishConnection('r');

    $parent_path = dirname($path);
    $path_query = new DiffusionGitPathIDQuery(
      array(
        $path,
        $parent_path,
      ));
    $path_map = $path_query->loadPathIDs();

    $path_id = $path_map[$path];
    $parent_path_id = $path_map[$parent_path];

    if (empty($path_id)) {
      $this->reason = self::REASON_IS_NONEXISTENT;
      return array();
    }

    if ($commit) {
      $slice_clause = 'AND svnCommit <= '.(int)$commit;
    } else {
      $slice_clause = '';
    }

    $index = queryfx_all(
      $conn_r,
      'SELECT pathID, max(svnCommit) maxCommit FROM %T WHERE
        repositoryID = %d AND parentID = %d
        %Q GROUP BY pathID',
      PhabricatorRepository::TABLE_FILESYSTEM,
      $repository->getID(),
      $path_id,
      $slice_clause);

    if (!$index) {
      if ($path == '/') {
        $this->reason = self::REASON_IS_EMPTY;
      } else {

        // NOTE: The parent path ID is included so this query can take
        // advantage of the table's primary key; it is uniquely determined by
        // the pathID but if we don't do the lookup ourselves MySQL doesn't have
        // the information it needs to avoid a table scan.

        $reasons = queryfx_all(
          $conn_r,
          'SELECT * FROM %T WHERE repositoryID = %d
              AND parentID = %d
              AND pathID = %d
            %Q ORDER BY svnCommit DESC LIMIT 2',
          PhabricatorRepository::TABLE_FILESYSTEM,
          $repository->getID(),
          $parent_path_id,
          $path_id,
          $slice_clause);

        $reason = reset($reasons);

        if (!$reason) {
          $this->reason = self::REASON_IS_NONEXISTENT;
        } else {
          $file_type = $reason['fileType'];
          if (empty($reason['existed'])) {
            $this->reason = self::REASON_IS_DELETED;
            $this->deletedAtCommit = $reason['svnCommit'];
            if (!empty($reasons[1])) {
              $this->existedAtCommit = $reasons[1]['svnCommit'];
            }
          } else if ($file_type == DifferentialChangeType::FILE_DIRECTORY) {
            $this->reason = self::REASON_IS_EMPTY;
          } else {
            $this->reason = self::REASON_IS_FILE;
          }
        }
      }
      return array();
    }

    if ($this->shouldOnlyTestValidity()) {
      return true;
    }

    $sql = array();
    foreach ($index as $row) {
      $sql[] = '('.(int)$row['pathID'].', '.(int)$row['maxCommit'].')';
    }

    $browse = queryfx_all(
      $conn_r,
      'SELECT *, p.path pathName
        FROM %T f JOIN %T p ON f.pathID = p.id
        WHERE repositoryID = %d
          AND parentID = %d
          AND existed = 1
        AND (pathID, svnCommit) in (%Q)
        ORDER BY pathName',
      PhabricatorRepository::TABLE_FILESYSTEM,
      PhabricatorRepository::TABLE_PATH,
      $repository->getID(),
      $path_id,
      implode(', ', $sql));

    $loadable_commits = array();
    foreach ($browse as $key => $file) {
      // We need to strip out directories because we don't store last-modified
      // in the filesystem table.
      if ($file['fileType'] != DifferentialChangeType::FILE_DIRECTORY) {
        $loadable_commits[] = $file['svnCommit'];
        $browse[$key]['hasCommit'] = true;
      }
    }

    $commits = array();
    $commit_data = array();
    if ($loadable_commits) {
      // NOTE: Even though these are integers, use '%Ls' because MySQL doesn't
      // use the second part of the key otherwise!
      $commits = id(new PhabricatorRepositoryCommit())->loadAllWhere(
        'repositoryID = %d AND commitIdentifier IN (%Ls)',
        $repository->getID(),
        $loadable_commits);
      $commits = mpull($commits, null, 'getCommitIdentifier');
      $commit_data = id(new PhabricatorRepositoryCommitData())->loadAllWhere(
        'commitID in (%Ld)',
        mpull($commits, 'getID'));
      $commit_data = mpull($commit_data, null, 'getCommitID');
    }

    $path_normal = DiffusionGitPathIDQuery::normalizePath($path);

    $results = array();
    foreach ($browse as $file) {

      $file_path = $file['pathName'];
      $file_path = ltrim(substr($file_path, strlen($path_normal)), '/');

      $result = new DiffusionRepositoryPath();
      $result->setPath($file_path);
//      $result->setHash($hash);
      $result->setFileType($file['fileType']);
//      $result->setFileSize($size);

      if (!empty($file['hasCommit'])) {
        $commit = idx($commits, $file['svnCommit']);
        if ($commit) {
          $data = idx($commit_data, $commit->getID());
          $result->setLastModifiedCommit($commit);
          $result->setLastCommitData($data);
        }
      }

      $results[] = $result;
    }

    return $results;
  }

}
