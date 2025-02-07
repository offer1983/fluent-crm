<?php

namespace FluentCrm\App\Http\Controllers;

use FluentCrm\App\Services\Libs\FileSystem;
use FluentCrm\Framework\Support\Arr;
use FluentCrm\Framework\Request\Request;
use FluentCrm\App\Models\Subscriber;

/**
 *  CsvController - REST API Handler Class
 *
 *  REST API Handler
 *
 * @package FluentCrm\App\Http
 *
 * @version 1.0.0
 */

class CsvController extends Controller
{

    /**
     * @param \FluentCrm\Framework\Request\Request $request
     * @return \WP_REST_Response
     * @throws \FluentCrm\Framework\Validator\ValidationException
     */
    public function upload(Request $request)
    {
        if (is_multisite()) {
            add_filter('upload_mimes', function ($types) {
                if (empty($types['csv'])) {
                    $types['csv'] = 'text/csv';
                }
                return $types;
            });
        }

        $files = $this->validate($this->request->files(), [
            'file' => 'mimetypes:' . implode(',', fluentcrmCsvMimes())
        ], [
            'file.mimetypes' => __('The file must be a valid CSV.', 'fluent-crm')
        ]);

        $delimeter = $request->get('delimiter', 'comma');


        if ($delimeter == 'comma') {
            $delimeter = ',';
        } else {
            $delimeter = ';';
        }

        $uploadedFiles = FileSystem::put($files);

        try {
            $csv = $this->getCsvReader(FileSystem::get($uploadedFiles[0]['file']));
            $csv->setDelimiter($delimeter);
            $headers = $csv->fetchOne();
        } catch (\Exception $exception) {
            return $this->sendError([
                'message' => $exception->getMessage()
            ]);
        }

        if (count($headers) != count(array_unique($headers))) {
            return $this->sendError([
                'message' => __('Looks like your csv has same name header multiple times. Please fix your csv first and remove any duplicate header column', 'fluent-crm')
            ]);
        }

        $mappables = Subscriber::mappables();
        $headerItems = array_filter($headers);
        $subscriberColumns = array_keys($mappables);

        $maps = [];

        foreach ($headerItems as $headerItem) {
            $tableMap = (in_array($headerItem, $subscriberColumns)) ? $headerItem : null;

            if (!$tableMap) {
                $santizedItem = str_replace(' ', '_', strtolower($headerItem));
                if (in_array($santizedItem, $subscriberColumns)) {
                    $tableMap = $santizedItem;
                }
            }

            $maps[] = [
                'csv'   => $headerItem,
                'table' => $tableMap
            ];
        }

        return $this->send([
            'file'    => $uploadedFiles[0]['file'],
            'headers' => $headerItems,
            'fields'  => $mappables,
            'columns' => apply_filters(
                'fluentcrm_subscriber_table_columns', $subscriberColumns
            ),
            'map'     => $maps
        ]);
    }

    public function import()
    {
        $inputs = $this->request->only([
            'map', 'tags', 'lists', 'file', 'update', 'new_status', 'double_optin_email', 'import_silently', 'force_update_status'
        ]);

        if (Arr::get($inputs, 'import_silently') == 'yes') {
            if (!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
                define('FLUENTCRM_DISABLE_TAG_LIST_EVENTS', true);
            }
        }

        $forceStatusChange = Arr::get($inputs, 'force_update_status') == 'yes';

        $delimeter = $this->request->get('delimiter', 'comma');

        if ($delimeter == 'comma') {
            $delimeter = ',';
        } else {
            $delimeter = ';';
        }

        $status = $inputs['new_status'];

        try {
            $reader = $this->getCsvReader(FileSystem::get($inputs['file']));
            $reader->setDelimiter($delimeter);

            if (method_exists($reader, 'getRecords')) {
                $aHeaders = $reader->fetchOne(0);

                $allRecords = $reader->getRecords($aHeaders);

                if (!is_array($allRecords)) {
                    $allRecords = iterator_to_array($allRecords, true);
                }

                unset($allRecords[0]);
                $allRecords = array_values($allRecords);
            } else {
                $aHeaders = $reader->fetchOne(0);
                $allRecords = $reader->fetchAssoc($aHeaders);
                if (!is_array($allRecords)) {
                    $allRecords = iterator_to_array($allRecords, true);
                }

                unset($allRecords[0]);

                $allRecords = array_values($allRecords);
            }
        } catch (\Exception $exception) {
            return $this->sendError([
                'message' => $exception->getMessage()
            ]);
        }

        $page = $this->request->get('importing_page', 1);
        $processPerRequest = 500;
        $offset = ($page - 1) * $processPerRequest;
        $records = array_slice($allRecords, $offset, $processPerRequest);


        $customFieldKeys = $this->customFieldKeys();
        $subscribers = [];
        $skipped = [];
        foreach ($records as $record) {
            if (!array_filter($record)) {
                continue;
            }

            $subscriber = [
                'custom_values' => []
            ];
            foreach ($inputs['map'] as $map) {
                if (!$map['table']) {
                    continue;
                }
                if (isset($map['csv'], $map['table'])) {
                    if (in_array($map['table'], $customFieldKeys)) {
                        $subscriber['custom_values'][$map['table']] = $record[$map['csv']];
                    } else {
                        $subscriber[$map['table']] = $record[$map['csv']];
                    }
                }
            }

            if (!array_key_exists('email', $subscriber)) {
                return $this->sendError(['email' => "The email field is required."], 422);
            }

            $subscriber['email'] = trim($subscriber['email']);

            if ($subscriber['email'] && is_email($subscriber['email'])) {
                $subscribers[] = $subscriber;
            } else {
                $skipped[] = $subscriber;
            }
        }

        if (!isset($inputs['tags'])) {
            $inputs['tags'] = [];
        }

        if (!isset($inputs['lists'])) {
            $inputs['lists'] = [];
        }

        $sendDoubleOptin = Arr::get($inputs, 'double_optin_email') == 'yes';

        $totalInput = count($subscribers);

        $result = Subscriber::import(
            $subscribers, $inputs['tags'], $inputs['lists'], $inputs['update'], $status, $sendDoubleOptin, $forceStatusChange
        );

        $totalSkipped = count($result['skips']) + count($skipped);

        $completed = $offset + count($records);
        $totalCount = count($allRecords);
        $hasMore = $completed < $totalCount;
        if (!$hasMore) {
            FileSystem::delete($inputs['file']);
        }

        return $this->sendSuccess([
            'total'                => $totalCount,
            'completed'            => $completed,
            'total_page'           => ceil($totalCount / $processPerRequest),
            'skipped'              => $totalSkipped,
            'invalid_contacts'     => $skipped,
            'skipped_contacts'     => $result['skips'],
            'invalid_email_counts' => count($skipped),
            'inserted'             => count($result['inserted']),
            'updated'              => count($result['updated']),
            'has_more'             => $hasMore,
            'last_page'            => $page,
            'tags'                 => $inputs['tags'],
            'lists'                => $inputs['lists'],
            'offset'               => $offset,
            'result'               => $result
        ]);
    }

    protected function customFieldKeys()
    {
        $fields = fluentcrm_get_option('contact_custom_fields', []);
        $keys = [];
        foreach ($fields as $field) {
            $keys[] = $field['slug'];
        }
        return $keys;
    }

    private function getCsvReader($file)
    {
        if (!class_exists(' \League\Csv\Reader')) {
            include FLUENTCRM_PLUGIN_PATH . 'app/Services/Libs/csv/autoload.php';
        }

        return \League\Csv\Reader::createFromString($file);
    }
}
