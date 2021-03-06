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

class DifferentialChangesetViewController extends DifferentialController {


  public function processRequest() {
    $request = $this->getRequest();

    $author_phid = $request->getUser()->getPHID();

    $id = $request->getStr('id');
    $vs = $request->getInt('vs');


    $changeset = id(new DifferentialChangeset())->load($id);
    if (!$changeset) {
      return new Aphront404Response();
    }

    if ($vs && ($vs != -1)) {
      $vs_changeset = id(new DifferentialChangeset())->load($vs);
      if (!$vs_changeset) {
        return new Aphront404Response();
      }
    }

    if (!$vs) {
      $right = $changeset;
      $left  = null;

      $right_source = $right->getID();
      $right_new = true;
      $left_source = $right->getID();
      $left_new = false;
    } else if ($vs == -1) {
      $right = null;
      $left = $changeset;

      $right_source = $left->getID();
      $right_new = false;
      $left_source = $left->getID();
      $left_new = true;
    } else {
      $right = $changeset;
      $left = $vs_changeset;

      $right_source = $right->getID();
      $right_new = true;
      $left_source = $left->getID();
      $left_new = true;
    }

    if ($left) {
      $left->attachHunks($left->loadHunks());
    }

    if ($right) {
      $right->attachHunks($right->loadHunks());
    }

    if ($left) {

      $left_data = $left->makeNewFile();
      if ($right) {
        $right_data = $right->makeNewFile();
      } else {
        $right_data = $left->makeOldFile();
      }

      $left_tmp = new TempFile();
      $right_tmp = new TempFile();
      Filesystem::writeFile($left_tmp, $left_data);
      Filesystem::writeFile($right_tmp, $right_data);
      list($err, $stdout) = exec_manual(
        '/usr/bin/diff -U65535 %s %s',
        $left_tmp,
        $right_tmp);

      $choice = nonempty($left, $right);
      if ($stdout) {
        $parser = new ArcanistDiffParser();
        $changes = $parser->parseDiff($stdout);
        $diff = DifferentialDiff::newFromRawChanges($changes);
        $changesets = $diff->getChangesets();
        $first = reset($changesets);
        $choice->attachHunks($first->getHunks());
      } else {
        $choice->attachHunks(array());
      }

      $changeset = $choice;
      $changeset->setID(null);
    }

    $range_s = null;
    $range_e = null;
    $mask = array();

    $range = $request->getStr('range');
    if ($range) {
      $match = null;
      if (preg_match('@^(\d+)-(\d+)(?:/(\d+)-(\d+))?$@', $range, $match)) {
        $range_s = (int)$match[1];
        $range_e = (int)$match[2];
        if (count($match) > 3) {
          $start = (int)$match[3];
          $len = (int)$match[4];
          for ($ii = $start; $ii < $start + $len; $ii++) {
            $mask[$ii] = true;
          }
        }
      }
    }

    $parser = new DifferentialChangesetParser();
    $parser->setChangeset($changeset);
    $parser->setRightSideCommentMapping($right_source, $right_new);
    $parser->setLeftSideCommentMapping($left_source, $left_new);
    $parser->setWhitespaceMode($request->getStr('whitespace'));

    $phids = array();
    $inlines = $this->loadInlineComments($id, $author_phid);
    foreach ($inlines as $inline) {
      $parser->parseInlineComment($inline);
      $phids[$inline->getAuthorPHID()] = true;
    }
    $phids = array_keys($phids);

    $handles = id(new PhabricatorObjectHandleData($phids))
      ->loadHandles();
    $parser->setHandles($handles);

    $factory = new DifferentialMarkupEngineFactory();
    $engine = $factory->newDifferentialCommentMarkupEngine();
    $parser->setMarkupEngine($engine);

    if ($request->isAjax()) {
      // TODO: This is sort of lazy, the effect is just to not render "Edit"
      // links on the "standalone view".
      $parser->setUser($request->getUser());
    }

    $output = $parser->render($range_s, $range_e, $mask);

    if ($request->isAjax()) {
      return id(new AphrontAjaxResponse())
        ->setContent($output);
    }

    Javelin::initBehavior('differential-show-more', array(
      'uri' => '/differential/changeset/',
    ));

    $detail = new DifferentialChangesetDetailView();
    $detail->setChangeset($changeset);
    $detail->appendChild($output);
    $detail->setRevisionID($request->getInt('revision_id'));

    $output =
      '<div class="differential-primary-pane">'.
        '<div class="differential-review-stage">'.
          $detail->render().
        '</div>'.
      '</div>';

    return $this->buildStandardPageResponse(
      array(
        $output
      ),
      array(
        'title' => 'Changeset View',
      ));
  }

  private function loadInlineComments($changeset_id, $author_phid) {
    return id(new DifferentialInlineComment())->loadAllWhere(
      'changesetID = %d AND (commentID IS NOT NULL OR authorPHID = %s)',
      $changeset_id,
      $author_phid);
  }


}
