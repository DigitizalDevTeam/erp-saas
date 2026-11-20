<?php

namespace App\Http\Controllers;

use App\Exports\CustomerExport;
use App\Imports\CustomerImport;
use App\Models\Customer;
use App\Models\CustomerCreditLedger;
use App\Models\CustomField;
use App\Models\Transaction;
use App\Models\PriceLevel;
use App\Models\Utility;
use App\Services\CustomerCreditService;
use Auth;
use App\Models\User;
use App\Models\Plan;
use File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Illuminate\Validation\Rule;
use App\Models\CustomerBillingAddress;
use App\Models\CustomerShippingAddress;
class CustomerController extends Controller
{

    public function dashboard()
    {
        $data['invoiceChartData'] = \Auth::user()->invoiceChartData();

        return view('customer.dashboard', $data);
    }

    public function index()
    {
        if(\Auth::user()->can('manage customer'))
        {
            $customers = Customer::forDocuments()->get();
            return view('customer.index', compact('customers'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function create()
    {
        if(\Auth::user()->can('create customer'))
        {
            $customFields = CustomField::where('created_by', '=', \Auth::user()->creatorId())->where('module', '=', 'customer')->get();
            $priceLevels = PriceLevel::where('created_by', '=', \Auth::user()->creatorId())->get();
            return view('customer.create', compact('customFields', 'priceLevels'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }


    public function store(Request $request)
    {
        if(\Auth::user()->can('create customer'))
        {

            $rules = [
                'name' => 'required',
                'contact' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/',
                'email' => [
                    'required',
                    Rule::unique('customers')->where(function ($query) {
                        return $query->where('created_by', \Auth::user()->id);
                    })
                ],
                'contacts' => 'array',
                'contacts.*.role' => 'nullable|string|max:100',
                'contacts.*.name' => 'nullable|string|max:255',
                'contacts.*.phone' => 'nullable|string|max:30',
                'contacts.*.email' => 'nullable|email|max:255',
                'contacts.*.identification' => 'nullable|string|max:100',
                'bank_account.bank_name' => 'nullable|string|max:255',
                'bank_account.account_number' => 'nullable|string|max:50',
                'bank_account.account_holder' => 'nullable|string|max:255',
                'bank_account.ifsc' => 'nullable|string|max:50',
                'additional_emails' => 'nullable|array',
                'kyc' => 'nullable|array',
                'kyc.*.document_type' => 'nullable|string|max:100',
                'kyc.*.document_number' => 'nullable|string|max:100',
                'kyc.*.document' => 'nullable|file|mimes:pdf,jpg,jpeg,png',
                'contact_kyc' => 'nullable|array',
                'contact_kyc.*.document_type' => 'nullable|string|max:100',
                'contact_kyc.*.document_number' => 'nullable|string|max:100',
                'contact_kyc.*.document' => 'nullable|file|mimes:pdf,jpg,jpeg,png',
            ];

            // Normalize KYC inputs to the repeater (list) form before
            // validation, so a stale single-document post
            // (kyc[document_type] / kyc[document_number]) is accepted too.
            $request->merge([
                'kyc' => self::normalizeKycRequestInput($request->input('kyc')),
                'contact_kyc' => self::normalizeKycRequestInput($request->input('contact_kyc')),
            ]);


            $validator = \Validator::make($request->all(), $rules);

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->route('customer.index')->with('error', $messages->first());
            }

            $objCustomer    = \Auth::user();
            $creator        = User::find($objCustomer->creatorId());
            $total_customer = $objCustomer->countCustomers();
            $plan           = Plan::find($creator->plan);

            $default_language          = DB::table('settings')->select('value')->where('name', 'default_language')->first();
            if($total_customer < $plan->max_customers || $plan->max_customers == -1)
            {
                $customer                  = new Customer();
                $customer->customer_id     = $this->customerNumber();
                $customer->name            = $request->name;
                $customer->contact         = $request->contact;
                $customer->email           = $request->email;
                $customer->notification_email = $request->filled('notification_email') ? $request->notification_email : null;
                $customer->notification_phone = $request->filled('notification_phone') ? $request->notification_phone : null;
                foreach ([
                    'notify_account_email', 'notify_account_sms', 'notify_account_whatsapp',
                    'notify_payment_email', 'notify_payment_sms', 'notify_payment_whatsapp',
                    'notify_invoice_email', 'notify_invoice_sms', 'notify_invoice_whatsapp',
                ] as $nf) {
                    $customer->{$nf} = $request->has($nf);
                }
                $customer->tax_number      =$request->tax_number;
                $customer->price_level_id      =$request->price_level_id ?? null;
                $customer->payment_type      =$request->payment_type;
                $customer->credit_terms      =$request->credit_terms;
                $customer->credit_limit      = $request->filled('credit_limit') ? $request->credit_limit : 0;
                $customer->created_by      = \Auth::user()->creatorId();
                $customer->is_walk_in      = false;
                // $customer->billing_name    = $request->billing_name;
                // $customer->billing_country = $request->billing_country;
                // $customer->billing_state   = $request->billing_state;
                // $customer->billing_city    = $request->billing_city;
                // $customer->billing_phone   = $request->billing_phone;
                // $customer->billing_zip     = $request->billing_zip;
                // $customer->billing_address = $request->billing_address;

                // $customer->shipping_name    = $request->shipping_name;
                // $customer->shipping_country = $request->shipping_country;
                // $customer->shipping_state   = $request->shipping_state;
                // $customer->shipping_city    = $request->shipping_city;
                // $customer->shipping_phone   = $request->shipping_phone;
                // $customer->shipping_zip     = $request->shipping_zip;
                // $customer->shipping_address = $request->shipping_address;

                $customer->lang = !empty($default_language) ? $default_language->value : '';
                
                $customer->bank_account = json_encode($request->bank_account);

                // Save additional emails as JSON string or CSV
                if ($request->additional_emails) {
                    // accept comma separated or array
                    $emails = is_array($request->additional_emails) ? $request->additional_emails : explode(',', $request->additional_emails);
                    $customer->additional_emails = json_encode(array_map('trim', $emails));
                }
            
                // Save contacts as JSON or related table (simplest JSON here)
                // contacts[0] is the main contact person; further entries
                // are additional contacts. A contact is kept when ANY of
                // its fields (role, name, phone, email, identification)
                // is filled in.
                $contacts = collect($request->contacts)
                    ->filter(function ($contact) {
                        return !empty($contact['role']) || !empty($contact['name']) || !empty($contact['phone'])
                            || !empty($contact['email']) || !empty($contact['identification']);
                    })
                    ->values()
                    ->toArray();
                
                $customer->contacts = json_encode($contacts);
            
                // Save KYC documents (company) + contact-person KYC as
                // JSON arrays of {document_type, document_number, file_path}.
                // Company files go to kyc_documents/, contact-person files
                // to kyc_documents/contact/. contact_kyc is guarded: the
                // column is added by migration 310008, and schemas that
                // have not run it yet must not 500 the whole create.
                $customer->kyc = json_encode($this->buildKycDocuments($request, 'kyc', 'kyc_documents'));
                if (\Schema::hasColumn('customers', 'contact_kyc')) {
                    $customer->contact_kyc = json_encode($this->buildKycDocuments($request, 'contact_kyc', 'kyc_documents/contact'));
                }

                $customer->save();
                
                    
                // Billing addresses
                if (!empty($request->billing_name) && is_array($request->billing_name)) {
                    foreach ($request->billing_name as $key => $item) {
                        // Skip if required data is missing
                        if (empty($item)) continue;
                
                        $customer_billing_address = new CustomerBillingAddress();
                        $customer_billing_address->name    = $item;
                        $customer_billing_address->country = $request->billing_country[$key] ?? null;
                        $customer_billing_address->state   = $request->billing_state[$key] ?? null;
                        $customer_billing_address->city    = $request->billing_city[$key] ?? null;
                        $customer_billing_address->phone   = $request->billing_phone[$key] ?? null;
                        $customer_billing_address->zip     = $request->billing_zip[$key] ?? null;
                        $customer_billing_address->address = $request->billing_address[$key] ?? null;
                        $customer_billing_address->customer_id = $customer->id;
                        $customer_billing_address->save();
                    }
                }
                
                // Shipping addresses
                if (!empty($request->shipping_name) && is_array($request->shipping_name)) {
                    foreach ($request->shipping_name as $key => $item) {
                        // Skip if required data is missing
                        if (empty($item)) continue;
                
                        $customer_shipping_address = new CustomerShippingAddress();
                        $customer_shipping_address->name    = $item;
                        $customer_shipping_address->country = $request->shipping_country[$key] ?? null;
                        $customer_shipping_address->state   = $request->shipping_state[$key] ?? null;
                        $customer_shipping_address->city    = $request->shipping_city[$key] ?? null;
                        $customer_shipping_address->phone   = $request->shipping_phone[$key] ?? null;
                        $customer_shipping_address->zip     = $request->shipping_zip[$key] ?? null;
                        $customer_shipping_address->address = $request->shipping_address[$key] ?? null;
                        $customer_shipping_address->customer_id = $customer->id;
                        $customer_shipping_address->save();
                    }
                }

               
                CustomField::saveData($customer, $request->customField);
            }
            else
            {
                return redirect()->back()->with('error', __('Your user limit is over, Please upgrade plan.'));
            }

            //For Notification
            $setting  = Utility::settings(\Auth::user()->creatorId());
            $customerNotificationArr = [
                'user_name' => \Auth::user()->name,
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
            ];

            //Twilio Notification
            if(isset($setting['twilio_customer_notification']) && $setting['twilio_customer_notification'] ==1)
            {
                Utility::send_twilio_msg($request->contact,'new_customer', $customerNotificationArr);
            }


            return redirect()->route('customer.index')->with('success', __('Customer successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Create a customer from POS (AJAX). Returns JSON. No page redirect.
     * Permission: create customer OR manage pos.
     */
    public function storeFromPos(Request $request)
    {
        if (!\Auth::user()->can('create customer') && !\Auth::user()->can('manage pos')) {
            return response()->json(['success' => false, 'message' => __('Permission denied.')], 403);
        }

        $rules = [
            'name' => 'required|string|max:255',
            'contact' => 'required|string|max:50',
            'email' => [
                'required',
                'email',
                Rule::unique('customers')->where(function ($query) {
                    return $query->where('created_by', \Auth::user()->creatorId());
                }),
            ],
        ];
        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->getMessageBag()->first(),
            ], 422);
        }

        $objCustomer = \Auth::user();
        $creator = User::find($objCustomer->creatorId());
        $total_customer = $objCustomer->countCustomers();
        $plan = Plan::find($creator->plan);
        if ($plan && $plan->max_customers != -1 && $total_customer >= $plan->max_customers) {
            return response()->json(['success' => false, 'message' => __('Your user limit is over, Please upgrade plan.')], 422);
        }

        $default_language = DB::table('settings')->select('value')->where('name', 'default_language')->first();
        $customer = new Customer();
        $customer->customer_id = $this->customerNumber();
        $customer->name = $request->name;
        $customer->contact = $request->contact;
        $customer->email = $request->email;
        $customer->tax_number = $request->filled('tax_number') ? $request->tax_number : null;
        $customer->billing_address = $request->filled('billing_address') ? $request->billing_address : null;
        $customer->payment_type = 'cash';
        $customer->credit_terms = null;
        $customer->created_by = \Auth::user()->creatorId();
        // POS walk-in: reusable in POS only; excluded from CRM / document customer lists
        $customer->is_walk_in = true;
        $customer->lang = !empty($default_language) ? $default_language->value : '';
        $customer->bank_account = json_encode([]);
        $customer->save();

        return response()->json([
            'success' => true,
            'message' => __('Customer successfully created.'),
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'contact' => $customer->contact,
                'is_walk_in' => true,
            ],
        ]);
    }


    public function show($ids)
    {
        try {
            $id       = Crypt::decrypt($ids);
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', __('Customer Not Found.'));
        }
        $id       = \Crypt::decrypt($ids);
        $customer = Customer::with('billingAddresses','shippingAddresses')->find($id);

        // Phase 3: the customer's credit ledger (newest first) and
        // the amount still usable (unexpired lots only). The table
        // guard keeps suites with minimal fixtures (no ledger table)
        // from 500ing — the service already guards the same way.
        $creditLedger = Schema::hasTable('customer_credit_ledger')
            ? CustomerCreditLedger::where('created_by', \Auth::user()->creatorId())
                ->where('customer_id', $id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
            : collect();
        $usableCredit = app(CustomerCreditService::class)->usableCredit(
            (int) \Auth::user()->creatorId(),
            (int) $id
        );

        return view('customer.show', compact('customer', 'creditLedger', 'usableCredit'));
    }


    public function edit($id)
    {
        if(\Auth::user()->can('edit customer'))
        {
            $customer              = Customer::find($id);
            $customer->customField = CustomField::getData($customer, 'customer');

            $customFields = CustomField::where('created_by', '=', \Auth::user()->creatorId())->where('module', '=', 'customer')->get();
            $priceLevels = PriceLevel::where('created_by', '=', \Auth::user()->creatorId())->get();
            return view('customer.edit', compact('customer', 'customFields', 'priceLevels'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }


    public function update(Request $request, Customer $customer)
    {

        if(\Auth::user()->can('edit customer'))
        {

            $rules = [
                'name' => 'required',
                'contact' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/',
                'contacts' => 'array',
                'contacts.*.role' => 'nullable|string|max:100',
                'contacts.*.name' => 'nullable|string|max:255',
                'contacts.*.phone' => 'nullable|string|max:30',
                'contacts.*.email' => 'nullable|email|max:255',
                'contacts.*.identification' => 'nullable|string|max:100',
                
                'bank_account.bank_name' => 'nullable|string|max:255',
                'bank_account.account_number' => 'nullable|string|max:50',
                'bank_account.account_holder' => 'nullable|string|max:255',
                'bank_account.ifsc' => 'nullable|string|max:50',
                
                'additional_emails' => 'nullable|array',
                
                'kyc' => 'nullable|array',
                'kyc.*.document_type' => 'nullable|string|max:100',
                'kyc.*.document_number' => 'nullable|string|max:100',
                'kyc.*.document' => 'nullable|file|mimes:pdf,jpg,jpeg,png',
                'contact_kyc' => 'nullable|array',
                'contact_kyc.*.document_type' => 'nullable|string|max:100',
                'contact_kyc.*.document_number' => 'nullable|string|max:100',
                'contact_kyc.*.document' => 'nullable|file|mimes:pdf,jpg,jpeg,png',

            ];

            // Normalize KYC inputs to the repeater (list) form before
            // validation (see store()).
            $request->merge([
                'kyc' => self::normalizeKycRequestInput($request->input('kyc')),
                'contact_kyc' => self::normalizeKycRequestInput($request->input('contact_kyc')),
            ]);


            $validator = \Validator::make($request->all(), $rules);
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->route('customer.index')->with('error', $messages->first());
            }

            $customer->name             = $request->name;
            $customer->contact          = $request->contact;
            $customer->email           = $request->email;
            $customer->notification_email = $request->filled('notification_email') ? $request->notification_email : null;
            $customer->notification_phone = $request->filled('notification_phone') ? $request->notification_phone : null;
            foreach ([
                'notify_account_email', 'notify_account_sms', 'notify_account_whatsapp',
                'notify_payment_email', 'notify_payment_sms', 'notify_payment_whatsapp',
                'notify_invoice_email', 'notify_invoice_sms', 'notify_invoice_whatsapp',
            ] as $nf) {
                $customer->{$nf} = $request->has($nf);
            }
            $customer->tax_number      =$request->tax_number;
            $customer->price_level_id      =$request->price_level_id ?? null;
            $customer->payment_type      =$request->payment_type;
            $customer->credit_terms      =$request->credit_terms;
            $customer->credit_limit      = $request->filled('credit_limit') ? $request->credit_limit : 0;
            $customer->created_by       = \Auth::user()->creatorId();
            // $customer->billing_name     = $request->billing_name;
            // $customer->billing_country  = $request->billing_country;
            // $customer->billing_state    = $request->billing_state;
            // $customer->billing_city     = $request->billing_city;
            // $customer->billing_phone    = $request->billing_phone;
            // $customer->billing_zip      = $request->billing_zip;
            // $customer->billing_address  = $request->billing_address;
            // $customer->shipping_name    = $request->shipping_name;
            // $customer->shipping_country = $request->shipping_country;
            // $customer->shipping_state   = $request->shipping_state;
            // $customer->shipping_city    = $request->shipping_city;
            // $customer->shipping_phone   = $request->shipping_phone;
            // $customer->shipping_zip     = $request->shipping_zip;
            // $customer->shipping_address = $request->shipping_address;
            
            
            // Update bank account
            $customer->bank_account = json_encode($request->bank_account);
        
            // Update additional emails (comma-separated or array)
            if ($request->filled('additional_emails')) {
                $emails = is_array($request->additional_emails)
                    ? $request->additional_emails
                    : explode(',', $request->additional_emails);
                $customer->additional_emails = json_encode(array_map('trim', $emails));
            } else {
                $customer->additional_emails = json_encode([]);
            }
        
            // Update contacts (contacts[0] is the main contact person)
            $contacts = collect($request->contacts)
                ->filter(function ($contact) {
                    return !empty($contact['role']) || !empty($contact['name']) || !empty($contact['phone'])
                        || !empty($contact['email']) || !empty($contact['identification']);
                })
                ->values()
                ->toArray();
            
            $customer->contacts = json_encode($contacts);
        
            // Update KYC documents (company + contact person). Files
            // dropped from the form (removed rows, replaced files) are
            // deleted so they do not orphan in storage.
            $oldKycFiles = collect($customer->kycDocuments())
                ->pluck('file_path')
                ->merge(collect($customer->contactKycDocuments())->pluck('file_path'))
                ->filter()
                ->values()
                ->all();
            $customer->kyc = json_encode($this->buildKycDocuments($request, 'kyc', 'kyc_documents'));
            if (\Schema::hasColumn('customers', 'contact_kyc')) {
                $customer->contact_kyc = json_encode($this->buildKycDocuments($request, 'contact_kyc', 'kyc_documents/contact'));
            }
            $newKycFiles = collect(json_decode($customer->kyc, true) ?? [])
                ->pluck('file_path')
                ->merge(collect(json_decode($customer->contact_kyc, true) ?? [])->pluck('file_path'))
                ->filter()
                ->values()
                ->all();
            $this->deleteRemovedKycFiles($oldKycFiles, $newKycFiles);
            
            
            $customer->save();
            
            
            CustomerBillingAddress::where("customer_id", $customer->id)->delete();
            CustomerShippingAddress::where("customer_id", $customer->id)->delete();
            // Billing addresses
            if (!empty($request->billing_name) && is_array($request->billing_name)) {
                
                foreach ($request->billing_name as $key => $item) {
                    // Skip if required data is missing
                    if (empty($item)) continue;
            
                    $customer_billing_address = new CustomerBillingAddress();
                    $customer_billing_address->name    = $item;
                    $customer_billing_address->country = $request->billing_country[$key] ?? null;
                    $customer_billing_address->state   = $request->billing_state[$key] ?? null;
                    $customer_billing_address->city    = $request->billing_city[$key] ?? null;
                    $customer_billing_address->phone   = $request->billing_phone[$key] ?? null;
                    $customer_billing_address->zip     = $request->billing_zip[$key] ?? null;
                    $customer_billing_address->address = $request->billing_address[$key] ?? null;
                    $customer_billing_address->customer_id = $customer->id;
                    $customer_billing_address->save();
                }
            }
            
            // Shipping addresses
            if (!empty($request->shipping_name) && is_array($request->shipping_name)) {
                
                foreach ($request->shipping_name as $key => $item) {
                    // Skip if required data is missing
                    if (empty($item)) continue;
            
                    $customer_shipping_address = new CustomerShippingAddress();
                    $customer_shipping_address->name    = $item;
                    $customer_shipping_address->country = $request->shipping_country[$key] ?? null;
                    $customer_shipping_address->state   = $request->shipping_state[$key] ?? null;
                    $customer_shipping_address->city    = $request->shipping_city[$key] ?? null;
                    $customer_shipping_address->phone   = $request->shipping_phone[$key] ?? null;
                    $customer_shipping_address->zip     = $request->shipping_zip[$key] ?? null;
                    $customer_shipping_address->address = $request->shipping_address[$key] ?? null;
                    $customer_shipping_address->customer_id = $customer->id;
                    $customer_shipping_address->save();
                }
            }

            CustomField::saveData($customer, $request->customField);

            return redirect()->route('customer.index')->with('success', __('Customer successfully updated.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }


    /**
     * Serve one KYC document file (view inline). Company-scoped: the
     * customer must belong to the logged-in user's company, and the file
     * is addressed by section + index — never by a raw path — so a
     * stored path cannot be swapped for another file.
     */
    public function downloadKycDocument($id, $section, $index)
    {
        $customer = Customer::find($id);
        if (!$customer || (int) $customer->created_by !== (int) \Auth::user()->creatorId()) {
            abort(404);
        }

        if ($section === 'contact') {
            $docs = $customer->contactKycDocuments();
        } elseif ($section === 'company') {
            $docs = $customer->kycDocuments();
        } else {
            abort(404);
        }

        $doc = $docs[(int) $index] ?? null;
        $path = is_array($doc) ? ($doc['file_path'] ?? null) : null;
        if (empty($path) || !str_starts_with((string) $path, 'kyc_documents/') || !\Storage::exists($path)) {
            abort(404);
        }

        return \Storage::response($path);
    }

    /**
     * Normalize a KYC request input to the repeater (list) form:
     * [['document_type','document_number','document','existing_file'], ...].
     * A stale single-document post (kyc[document_type] as scalars) is
     * wrapped as one row.
     */
    protected static function normalizeKycRequestInput($input): array
    {
        if (!is_array($input)) {
            return [];
        }
        if (isset($input['document_type']) || isset($input['document_number'])) {
            return [[
                'document_type' => $input['document_type'] ?? null,
                'document_number' => $input['document_number'] ?? null,
                'existing_file' => $input['existing_file'] ?? null,
            ]];
        }

        return array_values($input);
    }

    /**
     * Build the stored KYC document array for one section from the
     * request: each row keeps its existing file unless a new file was
     * uploaded for that row (stored under $directory). Rows with no
     * type, no number and no file are dropped.
     *
     * Iterates the raw input keys (not a re-indexed copy): removing a
     * middle row leaves sparse indices (0, 2), and the uploaded file
     * for a row is addressed by its original index (kyc.2.document).
     */
    protected function buildKycDocuments(Request $request, string $field, string $directory): array
    {
        $rows = $request->input($field);
        if (!is_array($rows)) {
            return [];
        }
        // Stale single-document post: kyc[document_type] as scalars.
        if (isset($rows['document_type']) || isset($rows['document_number'])) {
            $rows = [$rows];
        }

        $docs = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $filePath = $row['existing_file'] ?? null;
            $uploaded = $request->file("{$field}.{$i}.document");
            if ($uploaded) {
                $filePath = $uploaded->store($directory);
            }
            $doc = [
                'document_type' => $row['document_type'] ?? null,
                'document_number' => $row['document_number'] ?? null,
                'file_path' => $filePath,
            ];
            if (!empty($doc['document_type']) || !empty($doc['document_number']) || !empty($doc['file_path'])) {
                $docs[] = $doc;
            }
        }

        return $docs;
    }

    /**
     * Delete KYC files that were present before an update but are in
     * neither new list (removed rows / replaced files). Only paths
     * under kyc_documents/ are ever deleted.
     */
    protected function deleteRemovedKycFiles(array $oldPaths, array $newPaths): void
    {
        foreach (array_diff($oldPaths, $newPaths) as $path) {
            if (!is_string($path) || !str_starts_with($path, 'kyc_documents/')) {
                continue;
            }
            if (\Storage::exists($path)) {
                \Storage::delete($path);
            }
        }
    }


    public function destroy(Customer $customer)
    {
        if(\Auth::user()->can('delete customer'))
        {
            if($customer->created_by == \Auth::user()->creatorId())
            {
                $customer->delete();

                return redirect()->route('customer.index')->with('success', __('Customer successfully deleted.'));
            }
            else
            {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    function customerNumber()
    {
        $latest = Customer::where('created_by', '=', \Auth::user()->creatorId())->latest()->first();
        if(!$latest)
        {
            return 1;
        }

        return $latest->customer_id + 1;
    }

    public function customerLogout(Request $request)
    {
        \Auth::guard('customer')->logout();

        $request->session()->invalidate();

        return redirect()->route('customer.login');
    }

    public function payment(Request $request)
    {

        if(\Auth::user()->can('manage customer payment'))
        {
            $category = [
                'Invoice' => 'Invoice',
                'Deposit' => 'Deposit',
                'Sales' => 'Sales',
            ];

            $query = Transaction::where('user_id', \Auth::user()->id)->where('user_type', 'Customer')->where('type', 'Payment');
            if(!empty($request->date))
            {
                $date_range = explode(' - ', $request->date);
                $query->whereBetween('date', $date_range);
            }

            if(!empty($request->category))
            {
                $query->where('category', '=', $request->category);
            }
            $payments = $query->get();

            return view('customer.payment', compact('payments', 'category'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function transaction(Request $request)
    {
        if(\Auth::user()->can('manage customer payment'))
        {
            $category = [
                'Invoice' => 'Invoice',
                'Deposit' => 'Deposit',
                'Sales' => 'Sales',
            ];

            $query = Transaction::where('user_id', \Auth::user()->id)->where('user_type', 'Customer');

            if(!empty($request->date))
            {
                $date_range = explode(' - ', $request->date);
                $query->whereBetween('date', $date_range);
            }

            if(!empty($request->category))
            {
                $query->where('category', '=', $request->category);
            }
            $transactions = $query->get();

            // Phase 3: the customer's own credit ledger on their
            // statement — credit added, used, expired or reversed.
            // Same table guard as show(): minimal fixtures carry no
            // ledger table, and the view renders fine with an empty list.
            $creditLedger = Schema::hasTable('customer_credit_ledger')
                ? CustomerCreditLedger::where('customer_id', \Auth::user()->id)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->get()
                : collect();

            return view('customer.transaction', compact('transactions', 'category', 'creditLedger'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function profile()
    {
        $userDetail              = \Auth::user();
        $userDetail->customField = CustomField::getData($userDetail, 'customer');
        $customFields            = CustomField::where('created_by', '=', \Auth::user()->creatorId())->where('module', '=', 'customer')->get();

        return view('customer.profile', compact('userDetail', 'customFields'));
    }

    public function editprofile(Request $request)
    {
        $userDetail = \Auth::user();
        $user       = Customer::findOrFail($userDetail['id']);

        $this->validate(
            $request, [
                        'name' => 'required|max:120',
                        'contact' => 'required',
                        'email' => 'required|email|unique:users,email,' . $userDetail['id'],
                    ]
        );

        if($request->hasFile('profile'))
        {
            $filenameWithExt = $request->file('profile')->getClientOriginalName();
            $filename        = pathinfo($filenameWithExt, PATHINFO_FILENAME);
            $extension       = $request->file('profile')->getClientOriginalExtension();
            $fileNameToStore = $filename . '_' . time() . '.' . $extension;

            $dir        = storage_path('uploads/avatar/');
            $image_path = $dir . $userDetail['avatar'];

            if(File::exists($image_path))
            {
                File::delete($image_path);
            }

            if(!file_exists($dir))
            {
                mkdir($dir, 0777, true);
            }

            $path = $request->file('profile')->storeAs('uploads/avatar/', $fileNameToStore);

        }

        if(!empty($request->profile))
        {
            $user['avatar'] = $fileNameToStore;
        }
        $user['name']    = $request['name'];
        $user['email']   = $request['email'];
        $user['contact'] = $request['contact'];
        $user->save();
        CustomField::saveData($user, $request->customField);

        return redirect()->back()->with(
            'success', __('Profile successfully updated.')
        );
    }

    public function editBilling(Request $request)
    {
        $userDetail = \Auth::user();
        $user       = Customer::findOrFail($userDetail['id']);
        $this->validate(
            $request, [
                        'billing_name' => 'required',
                        'billing_country' => 'required',
                        'billing_state' => 'required',
                        'billing_city' => 'required',
                        'billing_phone' => 'required',
                        'billing_zip' => 'required',
                        'billing_address' => 'required',
                    ]
        );
        $input = $request->all();
        $user->fill($input)->save();

        return redirect()->back()->with(
            'success', __('Profile successfully updated.')
        );
    }

    public function editShipping(Request $request)
    {
        $userDetail = \Auth::user();
        $user       = Customer::findOrFail($userDetail['id']);
        $this->validate(
            $request, [
                        'shipping_name' => 'required',
                        'shipping_country' => 'required',
                        'shipping_state' => 'required',
                        'shipping_city' => 'required',
                        'shipping_phone' => 'required',
                        'shipping_zip' => 'required',
                        'shipping_address' => 'required',
                    ]
        );
        $input = $request->all();
        $user->fill($input)->save();

        return redirect()->back()->with(
            'success', __('Profile successfully updated.')
        );
    }


    public function changeLanquage($lang)
    {

        $user       = Auth::user();
        $user->lang = $lang;
        $user->save();

        return redirect()->back()->with('success', __('Language Change Successfully!'));

    }


    public function export()
    {
        $name = 'customer_' . date('Y-m-d i:h:s');
        $data = Excel::download(new CustomerExport(), $name . '.xlsx'); ob_end_clean();

        return $data;
    }

    public function importFile()
    {
        return view('customer.import');
    }



    public function customerImportdata(Request $request)
    {
        session_start();
        $html = '<h3 class="text-danger text-center">Below data is not inserted</h3></br>';
        $flag = 0;
        $html .= '<table class="table table-bordered"><tr>';
        try {
            $request = $request->data;
            $file_data = $_SESSION['file_data'];

            unset($_SESSION['file_data']);
        } catch (\Throwable $th) {
            $html = '<h3 class="text-danger text-center">Something went wrong, Please try again</h3></br>';
            return response()->json([
                'html' => true,
                'response' => $html,
            ]);
        }

        foreach ($file_data as $key => $row) {

            $customerByEmail = Customer::where('email', $row[$request['email']])->first();

            if(empty($customerByEmail)){
                try {
                    $customerData = new Customer();
                    $customerData->customer_id      = $this->customerNumber();
                    $customerData->name             = $row[$request['name']];
                    $customerData->email            = $row[$request['email']];
                    $customerData->contact          = $row[$request['contact']];
                    $customerData->is_active        = 1;
                    $customerData->billing_name     = $row[$request['billing_name']];
                    $customerData->billing_country  = $row[$request['billing_country']];
                    $customerData->billing_state    = $row[$request['billing_state']];
                    $customerData->billing_city     = $row[$request['billing_city']];
                    $customerData->billing_phone    = $row[$request['billing_phone']];
                    $customerData->billing_zip      = $row[$request['billing_zip']];
                    $customerData->billing_address  = $row[$request['billing_address']];
                    $customerData->shipping_name    = $row[$request['shipping_name']];
                    $customerData->shipping_country = $row[$request['shipping_country']];
                    $customerData->shipping_state   = $row[$request['shipping_state']];
                    $customerData->shipping_city    = $row[$request['shipping_city']];
                    $customerData->shipping_phone   = $row[$request['shipping_phone']];
                    $customerData->shipping_zip     = $row[$request['shipping_zip']];
                    $customerData->shipping_address = $row[$request['shipping_address']];
                    $customerData->balance          = $row[$request['balance']];
                    $customerData->created_by       = \Auth::user()->creatorId();
                    $customerData->save();

                } catch (\Exception $e) {
                    $flag = 1;
                    $html .= '<tr>';

                    $html .= '<td>' . (isset($row[$request['name']]) ? $row[$request['name']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['email']]) ? $row[$request['email']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['contact']]) ? $row[$request['contact']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['billing_name']]) ? $row[$request['billing_name']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['billing_country']]) ? $row[$request['billing_country']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['billing_state']]) ? $row[$request['billing_state']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['billing_city']]) ? $row[$request['billing_city']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['billing_phone']]) ? $row[$request['billing_phone']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['billing_zip']]) ? $row[$request['billing_zip']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['billing_address']]) ? $row[$request['billing_address']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['shipping_name']]) ? $row[$request['shipping_name']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['shipping_country']]) ? $row[$request['shipping_country']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['shipping_state']]) ? $row[$request['shipping_state']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['shipping_city']]) ? $row[$request['shipping_city']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['shipping_phone']]) ? $row[$request['shipping_phone']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['shipping_zip']]) ? $row[$request['shipping_zip']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['shipping_address']]) ? $row[$request['shipping_address']] : '-') . '</td>';
                    $html .= '<td>' . (isset($row[$request['balance']]) ? $row[$request['balance']] : '-') . '</td>';

                    $html .= '</tr>';
                }
            } else {
                $flag = 1;
                $html .= '<tr>';

                $html .= '<td>' . (isset($row[$request['name']]) ? $row[$request['name']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['email']]) ? $row[$request['email']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['contact']]) ? $row[$request['contact']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['billing_name']]) ? $row[$request['billing_name']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['billing_country']]) ? $row[$request['billing_country']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['billing_state']]) ? $row[$request['billing_state']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['billing_city']]) ? $row[$request['billing_city']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['billing_phone']]) ? $row[$request['billing_phone']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['billing_zip']]) ? $row[$request['billing_zip']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['billing_address']]) ? $row[$request['billing_address']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['shipping_name']]) ? $row[$request['shipping_name']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['shipping_country']]) ? $row[$request['shipping_country']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['shipping_state']]) ? $row[$request['shipping_state']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['shipping_city']]) ? $row[$request['shipping_city']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['shipping_phone']]) ? $row[$request['shipping_phone']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['shipping_zip']]) ? $row[$request['shipping_zip']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['shipping_address']]) ? $row[$request['shipping_address']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['balance']]) ? $row[$request['balance']] : '-') . '</td>';

                $html .= '</tr>';
            }
        }

        $html .= '
                </table>
                <br />
                ';

        if ($flag == 1) {

            return response()->json([
                'html' => true,
                'response' => $html,
            ]);
        } else {
            return response()->json([
                'html' => false,
                'response' => 'Data Imported Successfully',
            ]);
        }

    }


    public function searchCustomers(Request $request)
    {
        if (\Illuminate\Support\Facades\Auth::user()->can('manage customer')) {
            $customers = [];
            $search    = $request->search;
            if ($request->ajax() && isset($search) && !empty($search)) {
                $customers = Customer::select('id as value', 'name as label', 'email')->where('is_active', '=', 1)->where('created_by', '=', Auth::user()->getCreatedBy())->Where('name', 'LIKE', '%' . $search . '%')->orWhere('email', 'LIKE', '%' . $search . '%')->get();

                return json_encode($customers);
            }

            return $customers;
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }




}
