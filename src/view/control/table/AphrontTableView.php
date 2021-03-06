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

class AphrontTableView extends AphrontView {

  protected $data;
  protected $headers;
  protected $rowClasses = array();
  protected $columnClasses = array();
  protected $zebraStripes = true;
  protected $noDataString;
  protected $className;

  public function __construct(array $data) {
    $this->data = $data;
  }

  public function setHeaders(array $headers) {
    $this->headers = $headers;
    return $this;
  }

  public function setColumnClasses(array $column_classes) {
    $this->columnClasses = $column_classes;
    return $this;
  }

  public function setRowClasses(array $row_classes) {
    $this->rowClasses = $row_classes;
    return $this;
  }

  public function setNoDataString($no_data_string) {
    $this->noDataString = $no_data_string;
    return $this;
  }

  public function setClassName($class_name) {
    $this->className = $class_name;
    return $this;
  }

  public function setZebraStripes($zebra_stripes) {
    $this->zebraStripes = $zebra_stripes;
    return $this;
  }

  public function render() {
    require_celerity_resource('aphront-table-view-css');

    $class = $this->className;
    if ($class !== null) {
      $class = ' class="aphront-table-view '.$class.'"';
    } else {
      $class = ' class="aphront-table-view"';
    }
    $table = array('<table'.$class.'>');

    $col_classes = array();
    foreach ($this->columnClasses as $key => $class) {
      if (strlen($class)) {
        $col_classes[] = ' class="'.$class.'"';
      } else {
        $col_classes[] = null;
      }
    }

    $headers = $this->headers;
    if ($headers) {
      $table[] = '<tr>';
      foreach ($headers as $col_num => $header) {
        $class = idx($col_classes, $col_num);
        $table[] = '<th'.$class.'>'.$header.'</th>';
      }
      $table[] = '</tr>';
    }

    $data = $this->data;
    if ($data) {
      $row_num = 0;
      foreach ($data as $row) {
        while (count($row) > count($col_classes)) {
          $col_classes[] = null;
        }
        $class = idx($this->rowClasses, $row_num);
        if ($this->zebraStripes && ($row_num % 2)) {
          if ($class !== null) {
            $class = 'alt alt-'.$class;
          } else {
            $class = 'alt';
          }
        }
        if ($class !== null) {
          $class = ' class="'.$class.'"';
        }
        $table[] = '<tr'.$class.'>';
        $col_num = 0;
        foreach ($row as $value) {
          $class = $col_classes[$col_num];
          if ($class !== null) {
            $table[] = '<td'.$class.'>';
          } else {
            $table[] = '<td>';
          }
          $table[] = $value.'</td>';
          ++$col_num;
        }
        ++$row_num;
      }
    } else {
      $colspan = max(count($headers), 1);
      $table[] =
        '<tr class="no-data"><td colspan="'.$colspan.'">'.
          coalesce($this->noDataString, 'No data available.').
        '</td></tr>';
    }
    $table[] = '</table>';
    return implode('', $table);
  }
}

