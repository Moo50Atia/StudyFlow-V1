<?php

namespace App\Services\V3\Settings;

use App\Models\V3\ApprovedTemplate;
use Illuminate\Validation\ValidationException;

class SettingsSchema
{
    public const KNOWN_PROVIDERS = [
        'gemini_api' => 'GEMINI_API_KEY',
        'glm_api' => 'GLM_API_KEY',
        'deepseek_api' => 'DEEPSEEK_API_KEY',
    ];

    public static function validate(array $data): array
    {
        $errors = [];

        // Top level keys
        $allowedTopLevel = ['expected_active_version', 'limits', 'qualification', 'providers', 'templates'];
        $actualKeys = array_keys($data);
        $extraKeys = array_diff($actualKeys, $allowedTopLevel);
        $missingKeys = array_diff($allowedTopLevel, $actualKeys);

        if (! empty($missingKeys)) {
            foreach ($missingKeys as $key) {
                $errors[$key] = ["The {$key} field is required."];
            }
        }

        if (! empty($extraKeys)) {
            foreach ($extraKeys as $key) {
                $errors[$key] = ["The {$key} field is not allowed."];
            }
        }

        // 1. expected_active_version
        if (array_key_exists('expected_active_version', $data)) {
            $eav = $data['expected_active_version'];
            if (! static::isStrictInteger($eav) || (int) $eav < 0) {
                $errors['expected_active_version'] = ['The expected_active_version must be a non-negative integer.'];
            }
        }

        // 2. limits
        if (array_key_exists('limits', $data)) {
            if (! is_array($data['limits'])) {
                $errors['limits'] = ['The limits field must be an array.'];
            } else {
                $allowedLimits = [
                    'max_pdf_bytes',
                    'max_pdf_pages',
                    'max_ocr_pages',
                    'technical_retries',
                    'retry_delay_seconds',
                    'prompt_revisions',
                    'scene_repairs',
                    'coding_attempts',
                    'review_dormancy_days',
                ];
                $limitKeys = array_keys($data['limits']);
                $extraLimits = array_diff($limitKeys, $allowedLimits);
                $missingLimits = array_diff($allowedLimits, $limitKeys);

                if (! empty($extraLimits)) {
                    foreach ($extraLimits as $k) {
                        $errors["limits.{$k}"] = ["The limits.{$k} field is not allowed."];
                    }
                }
                if (! empty($missingLimits)) {
                    foreach ($missingLimits as $k) {
                        $errors["limits.{$k}"] = ["The limits.{$k} field is required."];
                    }
                }

                // Value ranges
                $maxPdfPages = null;
                if (isset($data['limits']['max_pdf_pages']) && static::isStrictInteger($data['limits']['max_pdf_pages'])) {
                    $maxPdfPages = (int) $data['limits']['max_pdf_pages'];
                    if ($maxPdfPages < 1 || $maxPdfPages > 1000) {
                        $errors['limits.max_pdf_pages'] = ['The max_pdf_pages must be between 1 and 1000.'];
                    }
                } elseif (isset($data['limits']['max_pdf_pages'])) {
                    $errors['limits.max_pdf_pages'] = ['The max_pdf_pages must be an integer.'];
                }

                if (isset($data['limits']['max_pdf_bytes'])) {
                    if (! static::isStrictInteger($data['limits']['max_pdf_bytes']) || (int) $data['limits']['max_pdf_bytes'] < 1 || (int) $data['limits']['max_pdf_bytes'] > 104857600) {
                        $errors['limits.max_pdf_bytes'] = ['The max_pdf_bytes must be between 1 and 104857600.'];
                    }
                }

                if (isset($data['limits']['max_ocr_pages'])) {
                    if (! static::isStrictInteger($data['limits']['max_ocr_pages'])) {
                        $errors['limits.max_ocr_pages'] = ['The max_ocr_pages must be an integer.'];
                    } else {
                        $maxOcr = (int) $data['limits']['max_ocr_pages'];
                        if ($maxOcr < 0 || ($maxPdfPages !== null && $maxOcr > $maxPdfPages)) {
                            $errors['limits.max_ocr_pages'] = ['The max_ocr_pages must be between 0 and max_pdf_pages.'];
                        }
                    }
                }

                $rangeChecks = [
                    'technical_retries' => [0, 10],
                    'retry_delay_seconds' => [1, 3600],
                    'prompt_revisions' => [0, 10],
                    'scene_repairs' => [0, 10],
                    'coding_attempts' => [1, 20],
                    'review_dormancy_days' => [1, 365],
                ];

                foreach ($rangeChecks as $field => [$min, $max]) {
                    if (isset($data['limits'][$field])) {
                        if (! static::isStrictInteger($data['limits'][$field]) || (int) $data['limits'][$field] < $min || (int) $data['limits'][$field] > $max) {
                            $errors["limits.{$field}"] = ["The limits.{$field} must be between {$min} and {$max}."];
                        }
                    }
                }
            }
        }

        // 3. qualification
        if (array_key_exists('qualification', $data)) {
            if (! is_array($data['qualification'])) {
                $errors['qualification'] = ['The qualification field must be an array.'];
            } else {
                $allowedQual = ['prompt', 'scene'];
                $qualKeys = array_keys($data['qualification']);
                $extraQual = array_diff($qualKeys, $allowedQual);
                $missingQual = array_diff($allowedQual, $qualKeys);

                if (! empty($extraQual)) {
                    foreach ($extraQual as $k) {
                        $errors["qualification.{$k}"] = ["The qualification.{$k} field is not allowed."];
                    }
                }
                if (! empty($missingQual)) {
                    foreach ($missingQual as $k) {
                        $errors["qualification.{$k}"] = ["The qualification.{$k} field is required."];
                    }
                }

                foreach (['prompt', 'scene'] as $target) {
                    if (isset($data['qualification'][$target]) && is_array($data['qualification'][$target])) {
                        $sub = $data['qualification'][$target];
                        $allowedSub = ['min_score', 'min_confidence'];
                        $subKeys = array_keys($sub);
                        $extraSub = array_diff($subKeys, $allowedSub);
                        $missingSub = array_diff($allowedSub, $subKeys);

                        if (! empty($extraSub)) {
                            foreach ($extraSub as $k) {
                                $errors["qualification.{$target}.{$k}"] = ["The qualification.{$target}.{$k} field is not allowed."];
                            }
                        }
                        if (! empty($missingSub)) {
                            foreach ($missingSub as $k) {
                                $errors["qualification.{$target}.{$k}"] = ["The qualification.{$target}.{$k} field is required."];
                            }
                        }

                        foreach (['min_score', 'min_confidence'] as $qField) {
                            if (isset($sub[$qField])) {
                                if (! static::isStrictInteger($sub[$qField]) || (int) $sub[$qField] < 0 || (int) $sub[$qField] > 100) {
                                    $errors["qualification.{$target}.{$qField}"] = ["The qualification.{$target}.{$qField} must be an integer between 0 and 100."];
                                }
                            }
                        }
                    }
                }
            }
        }

        // 4. providers
        if (array_key_exists('providers', $data)) {
            if (! is_array($data['providers'])) {
                $errors['providers'] = ['The providers field must be an array.'];
            } else {
                $allowedProv = ['prompt', 'feedback', 'coding_order'];
                $provKeys = array_keys($data['providers']);
                $extraProv = array_diff($provKeys, $allowedProv);
                $missingProv = array_diff($allowedProv, $provKeys);

                if (! empty($extraProv)) {
                    foreach ($extraProv as $k) {
                        $errors["providers.{$k}"] = ["The providers.{$k} field is not allowed."];
                    }
                }
                if (! empty($missingProv)) {
                    foreach ($missingProv as $k) {
                        $errors["providers.{$k}"] = ["The providers.{$k} field is required."];
                    }
                }

                // Single descriptors: prompt, feedback
                foreach (['prompt', 'feedback'] as $role) {
                    if (isset($data['providers'][$role])) {
                        static::validateProviderDescriptor(
                            $data['providers'][$role],
                            "providers.{$role}",
                            $errors
                        );
                    }
                }

                // coding_order: 1-3 descriptors, distinct IDs
                if (isset($data['providers']['coding_order'])) {
                    $order = $data['providers']['coding_order'];
                    if (! is_array($order) || ! array_is_list($order) || count($order) < 1 || count($order) > 3) {
                        $errors['providers.coding_order'] = ['The coding_order must be an array of 1 to 3 provider descriptors.'];
                    } else {
                        $seenIds = [];
                        foreach ($order as $idx => $desc) {
                            static::validateProviderDescriptor($desc, "providers.coding_order.{$idx}", $errors);
                            if (is_array($desc) && isset($desc['id'])) {
                                if (in_array($desc['id'], $seenIds, true)) {
                                    $errors['providers.coding_order'] = ['The coding_order contains duplicate provider IDs.'];
                                }
                                $seenIds[] = $desc['id'];
                            }
                        }
                    }
                }
            }
        }

        // 5. templates
        if (array_key_exists('templates', $data)) {
            if (! is_array($data['templates'])) {
                $errors['templates'] = ['The templates field must be an array.'];
            } else {
                $allowedTmpl = ['feedback', 'scene'];
                $tmplKeys = array_keys($data['templates']);
                $extraTmpl = array_diff($tmplKeys, $allowedTmpl);
                $missingTmpl = array_diff($allowedTmpl, $tmplKeys);

                if (! empty($extraTmpl)) {
                    foreach ($extraTmpl as $k) {
                        $errors["templates.{$k}"] = ["The templates.{$k} field is not allowed."];
                    }
                }
                if (! empty($missingTmpl)) {
                    foreach ($missingTmpl as $k) {
                        $errors["templates.{$k}"] = ["The templates.{$k} field is required."];
                    }
                }

                foreach (['feedback', 'scene'] as $tmplKind) {
                    if (isset($data['templates'][$tmplKind])) {
                        $ref = $data['templates'][$tmplKind];
                        if (! is_array($ref)) {
                            $errors["templates.{$tmplKind}"] = ["The templates.{$tmplKind} must be an object with id and checksum."];

                            continue;
                        }

                        $allowedRefKeys = ['id', 'checksum'];
                        $refKeys = array_keys($ref);
                        $extraRef = array_diff($refKeys, $allowedRefKeys);
                        $missingRef = array_diff($allowedRefKeys, $refKeys);

                        if (! empty($extraRef)) {
                            foreach ($extraRef as $k) {
                                $errors["templates.{$tmplKind}.{$k}"] = ["The templates.{$tmplKind}.{$k} field is not allowed."];
                            }
                        }
                        if (! empty($missingRef)) {
                            foreach ($missingRef as $k) {
                                $errors["templates.{$tmplKind}.{$k}"] = ["The templates.{$tmplKind}.{$k} field is required."];
                            }
                        }

                        if (empty($missingRef) && empty($extraRef)) {
                            $tmplId = (string) $ref['id'];
                            $tmplChecksum = (string) $ref['checksum'];

                            $templateRecord = ApprovedTemplate::find($tmplId);
                            if (! $templateRecord) {
                                $errors["templates.{$tmplKind}.id"] = ["Approved template '{$tmplId}' not found in catalog."];
                            } elseif ($templateRecord->kind !== $tmplKind) {
                                $errors["templates.{$tmplKind}.id"] = ["Template '{$tmplId}' is kind '{$templateRecord->kind}', expected '{$tmplKind}'."];
                            } elseif ($templateRecord->checksum !== $tmplChecksum) {
                                $errors["templates.{$tmplKind}.checksum"] = ["Template checksum mismatch for '{$tmplId}'."];
                            }
                        }
                    }
                }
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    private static function isStrictInteger(mixed $value): bool
    {
        if (is_bool($value) || is_null($value)) {
            return false;
        }

        if (is_int($value)) {
            return true;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return true;
        }

        return false;
    }

    private static function validateProviderDescriptor(mixed $desc, string $path, array &$errors): void
    {
        if (! is_array($desc)) {
            $errors[$path] = ["The {$path} must be a provider descriptor object."];

            return;
        }

        $allowedKeys = ['id', 'model', 'credential_ref'];
        $keys = array_keys($desc);
        $extraKeys = array_diff($keys, $allowedKeys);
        $missingKeys = array_diff($allowedKeys, $keys);

        if (! empty($extraKeys)) {
            foreach ($extraKeys as $k) {
                $errors["{$path}.{$k}"] = ["The {$path}.{$k} field is not allowed."];
            }
        }

        if (! empty($missingKeys)) {
            foreach ($missingKeys as $k) {
                $errors["{$path}.{$k}"] = ["The {$path}.{$k} field is required."];
            }
        }

        if (empty($missingKeys) && empty($extraKeys)) {
            $id = $desc['id'];
            $model = $desc['model'];
            $cred = $desc['credential_ref'];

            if (! is_string($id) || ! array_key_exists($id, static::KNOWN_PROVIDERS)) {
                $errors["{$path}.id"] = ["Unknown provider id '{$id}'."];
            } else {
                $expectedCred = static::KNOWN_PROVIDERS[$id];
                if ($cred !== $expectedCred) {
                    $errors["{$path}.credential_ref"] = ["The credential_ref for '{$id}' must be '{$expectedCred}'."];
                }
            }

            if (! is_string($model) || ! preg_match('/^[A-Za-z0-9._:\/-]{1,100}$/', $model)) {
                $errors["{$path}.model"] = ["The model must be 1-100 characters matching ^[A-Za-z0-9._:\/-]{1,100}$."];
            }
        }
    }
}
