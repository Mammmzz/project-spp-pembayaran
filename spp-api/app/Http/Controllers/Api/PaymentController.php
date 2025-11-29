<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InstallmentPayment;
use App\Services\FCMService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    /**
     * Update payment status after successful payment
     */
    public function updatePaymentStatus(Request $request)
    {
        logger()->info('🔔 updatePaymentStatus called', [
            'request_data' => $request->all(),
            'user_id' => Auth::id(),
        ]);

        $request->validate([
            'tagihan_id' => 'required|integer',
            'order_id' => 'required|string',
            'payment_type' => 'nullable|string',
        ]);

        $user = Auth::user();

        try {
            // Find tagihan
            $tagihan = DB::table('tagihan')
                ->where('id', $request->tagihan_id)
                ->where('user_id', $user->id)
                ->first();

            if (!$tagihan) {
            return response()->json([
                'status' => false,
                    'message' => 'Tagihan not found',
                ], 404);
    }

            // Check if already paid
            if ($tagihan->status === 'paid' || $tagihan->status === 'lunas') {
            return response()->json([
                'status' => true,
                    'message' => 'Tagihan already paid',
                    'data' => [
                        'tagihan_id' => $tagihan->id,
                        'status' => $tagihan->status,
                    ],
                ]);
            }

            // Check if this is an installment payment
            $isInstallment = $request->is_installment || strpos($request->order_id, 'CICILAN-') === 0;
            
            // Get payment amount - prefer amount from request, fallback to Midtrans API
            $paymentAmount = 0;
            if ($isInstallment) {
                // Use amount sent from Flutter directly (more reliable)
                if ($request->amount && $request->amount > 0) {
                    $paymentAmount = (float) $request->amount;
                    logger()->info('💰 Using amount from request', ['amount' => $paymentAmount]);
                } else {
                    // Fallback: Query Midtrans API for amount
                    $serverKey = 'Mid-server-dFFtiNj8J4--bPOtjAKrQ7Fg';
                    $auth = base64_encode($serverKey . ':');
                    $statusUrl = 'https://api.sandbox.midtrans.com/v2/' . $request->order_id . '/status';
                    
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $statusUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Accept: application/json',
                        'Content-Type: application/json',
                        'Authorization: Basic ' . $auth,
                    ]);
                    
                    $response = curl_exec($ch);
                    curl_close($ch);
                    
                    $statusData = json_decode($response, true);
                    $paymentAmount = (float) ($statusData['gross_amount'] ?? 0);
                    
                    logger()->info('🔍 Midtrans status check for installment (fallback)', [
                        'order_id' => $request->order_id,
                        'gross_amount' => $paymentAmount,
                        'transaction_status' => $statusData['transaction_status'] ?? 'unknown',
                    ]);
                }
            }

            // Update tagihan status
            logger()->info('🔄 Updating tagihan status', [
                'tagihan_id' => $request->tagihan_id,
                'user_id' => $user->id,
                'payment_type' => $request->payment_type,
                'is_installment' => $isInstallment,
                'payment_amount' => $paymentAmount,
            ]);
            
            if ($isInstallment && $paymentAmount > 0) {
                // Handle installment payment
                $currentTerbayar = (float) ($tagihan->terbayar ?? 0);
                $newTerbayar = $currentTerbayar + $paymentAmount;
                $totalAmount = (float) $tagihan->jumlah;
                
                // Determine new status
                $newStatus = ($newTerbayar >= $totalAmount) ? 'paid' : 'partial';
                
                $updated = DB::table('tagihan')
                    ->where('id', $request->tagihan_id)
                    ->update([
                        'terbayar' => $newTerbayar,
                        'status' => $newStatus,
                        'tanggal_bayar' => now(),
                        'metode_bayar' => ($request->payment_type ?? 'Midtrans') . ' (Cicilan)',
                        'updated_at' => now(),
                    ]);
                
                // Save installment payment history
                InstallmentPayment::create([
                    'tagihan_id' => $request->tagihan_id,
                    'user_id' => $user->id,
                    'order_id' => $request->order_id,
                    'amount' => $paymentAmount,
                    'payment_method' => $request->payment_type ?? 'Midtrans',
                    'status' => 'success',
                    'transaction_id' => $request->transaction_id ?? null,
                    'paid_at' => now(),
                ]);
                    
                logger()->info('✅ Installment payment processed', [
                    'current_terbayar' => $currentTerbayar,
                    'payment_amount' => $paymentAmount,
                    'new_terbayar' => $newTerbayar,
                    'total_amount' => $totalAmount,
                    'new_status' => $newStatus,
                ]);
            } else {
                // Handle full payment
                $updated = DB::table('tagihan')
                    ->where('id', $request->tagihan_id)
                    ->update([
                        'terbayar' => $tagihan->jumlah, // Set terbayar to full amount
                        'status' => 'paid',
                        'tanggal_bayar' => now(),
                        'metode_bayar' => $request->payment_type ?? 'Midtrans',
                        'updated_at' => now(),
                    ]);
            }
                
            logger()->info('✅ Tagihan updated', [
                'rows_affected' => $updated,
            ]);

            // Get updated tagihan
            $updatedTagihan = DB::table('tagihan')->find($request->tagihan_id);
            
            logger()->info('📊 Updated tagihan data', [
                'id' => $updatedTagihan->id ?? null,
                'status' => $updatedTagihan->status ?? null,
                'terbayar' => $updatedTagihan->terbayar ?? null,
                'tanggal_bayar' => $updatedTagihan->tanggal_bayar ?? null,
            ]);
            
            // Create in-app notification
            logger()->info('📝 Creating in-app notification', [
                'user_id' => $user->id,
                'bulan' => $updatedTagihan->bulan,
                'tahun' => $updatedTagihan->tahun,
                'is_installment' => $isInstallment,
            ]);
            
            // Determine notification message based on payment type
            $notifTitle = $isInstallment ? '💳 Cicilan Berhasil!' : '💰 Pembayaran Berhasil!';
            $remaining = (float) $updatedTagihan->jumlah - (float) ($updatedTagihan->terbayar ?? 0);
            
            if ($isInstallment && $updatedTagihan->status === 'partial') {
                $notifMessage = sprintf(
                    'Cicilan SPP %s %s senilai Rp %s berhasil. Sisa tagihan: Rp %s',
                    $updatedTagihan->bulan,
                    $updatedTagihan->tahun,
                    number_format($paymentAmount, 0, ',', '.'),
                    number_format($remaining, 0, ',', '.')
                );
            } else {
                $notifMessage = sprintf(
                    'Pembayaran SPP %s %s senilai Rp %s berhasil. Terima kasih!',
                    $updatedTagihan->bulan,
                    $updatedTagihan->tahun,
                    number_format($isInstallment ? $paymentAmount : $updatedTagihan->jumlah, 0, ',', '.')
                );
            }
            
            DB::table('notifications')->insert([
                'user_id' => $user->id,
                'type' => 'Pembayaran',
                'title' => $notifTitle,
                'message' => $notifMessage,
                'data' => json_encode([
                    'payment_id' => $updatedTagihan->id,
                    'bulan' => $updatedTagihan->bulan,
                    'tahun' => $updatedTagihan->tahun,
                    'jumlah' => $isInstallment ? $paymentAmount : $updatedTagihan->jumlah,
                    'terbayar' => $updatedTagihan->terbayar,
                    'status' => $updatedTagihan->status,
                    'is_installment' => $isInstallment,
                ]),
                'is_read' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            logger()->info('✅ In-app notification created successfully');
            
            // Send FCM notification
            try {
                if ($user->fcm_token) {
                    logger()->info('📲 Sending FCM notification', [
                        'fcm_token' => substr($user->fcm_token, 0, 20) . '...',
                    ]);
                    
                    $fcmService = new FCMService();
                    
                    $result = $fcmService->sendPaymentSuccessNotification($user->fcm_token, [
                        'id' => $updatedTagihan->id,
                        'bulan' => $updatedTagihan->bulan,
                        'tahun' => $updatedTagihan->tahun,
                        'jumlah' => $isInstallment ? $paymentAmount : $updatedTagihan->jumlah,
                        'metode_bayar' => $updatedTagihan->metode_bayar ?? 'virtual account',
                        'tanggal_bayar' => $updatedTagihan->tanggal_bayar, // Real payment time
                        'is_installment' => $isInstallment,
                    ]);
                    
                    if ($result) {
                        logger()->info('✅ FCM notification sent successfully');
                    } else {
                        logger()->warning('⚠️ FCM notification failed (returned false)');
                    }
                } else {
                    logger()->warning('⚠️ No FCM token found for user ' . $user->id);
                }
        } catch (\Exception $e) {
                // Don't fail the request if FCM fails
                logger()->error('❌ FCM notification failed: ' . $e->getMessage());
        }

            return response()->json([
                'status' => true,
                'message' => $isInstallment 
                    ? ($updatedTagihan->status === 'paid' ? 'Cicilan terakhir berhasil! Tagihan lunas.' : 'Cicilan berhasil diproses')
                    : 'Payment status updated successfully',
                'data' => [
                    'tagihan_id' => $request->tagihan_id,
                    'order_id' => $request->order_id,
                    'status' => $updatedTagihan->status,
                    'terbayar' => (int) ($updatedTagihan->terbayar ?? 0),
                    'remaining' => (int) $remaining,
                    'tanggal_bayar' => now()->format('Y-m-d H:i:s'),
                    'is_installment' => $isInstallment,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update payment status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all payments for Admin/Petugas
     */
    public function allPayments(Request $request)
    {
        try {
            $query = DB::table('tagihan')
                ->join('users', 'tagihan.user_id', '=', 'users.id')
                ->select(
                    'tagihan.id',
                    'tagihan.bulan',
                    'tagihan.tahun',
                    'tagihan.jumlah as amount',
                    'tagihan.terbayar',
                    'tagihan.status',
                    'tagihan.tanggal_bayar',
                    'tagihan.metode_bayar',
                    'tagihan.created_at',
                    'users.name as student_name',
                    'users.kelas as class'
                );

            // Filter by status if provided
            if ($request->has('status')) {
                $status = $request->status;
                if ($status === 'verified' || $status === 'paid' || $status === 'lunas') {
                    $query->whereIn('tagihan.status', ['paid', 'lunas', 'verified']);
                } elseif ($status === 'pending') {
                    $query->where('tagihan.status', 'pending');
                } elseif ($status === 'partial' || $status === 'cicilan') {
                    $query->where('tagihan.status', 'partial');
                } elseif ($status === 'unpaid') {
                    $query->where('tagihan.status', 'unpaid');
                } elseif ($status === 'failed') {
                    $query->where('tagihan.status', 'failed');
                }
            }

            // Order by latest first
            $payments = $query->orderBy('tagihan.updated_at', 'desc')
                ->limit($request->get('limit', 10))
                ->get();

            // Transform data
            $formattedPayments = $payments->map(function ($payment) {
                $terbayar = (float) ($payment->terbayar ?? 0);
                $total = (float) $payment->amount;
                
                // If status is paid/lunas, remaining should be 0
                if (in_array($payment->status, ['paid', 'lunas', 'verified'])) {
                    $remaining = 0;
                    $terbayar = $total; // Ensure terbayar equals total for paid bills
                } else {
                    $remaining = $total - $terbayar;
                }
                
                return [
                    'id' => $payment->id,
                    'student_name' => $payment->student_name,
                    'class' => $payment->class,
                    'bulan' => $payment->bulan,
                    'tahun' => $payment->tahun,
                    'amount' => (int) $payment->amount,
                    'terbayar' => $terbayar,
                    'remaining' => $remaining,
                    'status' => $payment->status === 'paid' || $payment->status === 'lunas' ? 'verified' : 
                               ($payment->status === 'partial' ? 'partial' : $payment->status),
                    'tanggal_bayar' => $payment->tanggal_bayar,
                    'metode_bayar' => $payment->metode_bayar,
                    'created_at' => $payment->created_at,
                ];
            });

            return response()->json([
                'status' => true,
                'message' => 'Payments retrieved successfully',
                'data' => $formattedPayments,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve payments',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get payment detail
     */
    public function paymentDetail($id)
    {
        try {
            $payment = DB::table('tagihan')
                ->join('users', 'tagihan.user_id', '=', 'users.id')
                ->select(
                    'tagihan.*',
                    'users.name as student_name',
                    'users.email as student_email',
                    'users.nis',
                    'users.nisn',
                    'users.kelas',
                    'users.jurusan'
                )
                ->where('tagihan.id', $id)
                ->first();

            if (!$payment) {
                return response()->json([
                    'status' => false,
                    'message' => 'Payment not found',
                ], 404);
            }

            // Get verification history or logs if any (optional)
            
            return response()->json([
                'status' => true,
                'message' => 'Payment detail retrieved successfully',
                'data' => $payment,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve payment detail',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify payment manually (Admin/Petugas)
     */
    public function verifyPayment(Request $request, $id)
    {
        try {
            $tagihan = DB::table('tagihan')->where('id', $id)->first();

            if (!$tagihan) {
                return response()->json([
                    'status' => false,
                    'message' => 'Tagihan not found',
                ], 404);
            }

            $status = $request->status; // 'verified' or 'rejected'
            
            if ($status === 'verified') {
                DB::table('tagihan')
                    ->where('id', $id)
                    ->update([
                        'status' => 'paid',
                        'terbayar' => $tagihan->jumlah,
                        'tanggal_bayar' => now(),
                        'metode_bayar' => 'Verifikasi Petugas',
                        'updated_at' => now(),
                    ]);
                    
                // Send FCM notification to user
                $user = \App\Models\User::find($tagihan->user_id);
                if ($user && $user->fcm_token) {
                    try {
                        $fcmService = new FCMService();
                        $fcmService->sendPaymentSuccessNotification($user->fcm_token, [
                            'id' => $tagihan->id,
                            'bulan' => $tagihan->bulan,
                            'tahun' => $tagihan->tahun,
                            'jumlah' => $tagihan->jumlah,
                            'metode_bayar' => 'Verifikasi Petugas',
                            'tanggal_bayar' => now(),
                        ]);
                        logger()->info('✅ FCM notification sent for verified payment');
                    } catch (\Exception $e) {
                        logger()->error('❌ FCM notification failed: ' . $e->getMessage());
                    }
                }
                
                // Create in-app notification
                DB::table('notifications')->insert([
                    'user_id' => $tagihan->user_id,
                    'type' => 'Pembayaran',
                    'title' => 'Pembayaran SPP Berhasil Diverifikasi',
                    'message' => 'Pembayaran SPP ' . $tagihan->bulan . ' ' . $tagihan->tahun . ' sebesar Rp ' . number_format($tagihan->jumlah, 0, ',', '.') . ' telah diverifikasi oleh petugas.',
                    'data' => json_encode([
                        'payment_id' => $tagihan->id,
                        'bulan' => $tagihan->bulan,
                        'tahun' => $tagihan->tahun,
                        'jumlah' => $tagihan->jumlah,
                    ]),
                    'is_read' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                    
                return response()->json([
                    'status' => true,
                    'message' => 'Payment verified successfully',
                ]);
            } else if ($status === 'rejected') {
                DB::table('tagihan')
                    ->where('id', $id)
                    ->update([
                        'status' => 'failed',
                        'updated_at' => now(),
                    ]);
                    
                return response()->json([
                    'status' => true,
                    'message' => 'Payment rejected',
                ]);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid status',
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to verify payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process Manual Payment (Cicilan)
     */
    public function manualPayment(Request $request, $id)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:1000',
            ]);

            $tagihan = DB::table('tagihan')->where('id', $id)->first();

            if (!$tagihan) {
                return response()->json(['status' => false, 'message' => 'Tagihan not found'], 404);
            }

            $amount = (float) $request->amount;
            $total = (float) $tagihan->jumlah;
            $terbayar = (float) ($tagihan->terbayar ?? 0);
            $remaining = $total - $terbayar;
            
            // Validate amount doesn't exceed remaining
            if ($amount > $remaining) {
                return response()->json([
                    'status' => false, 
                    'message' => 'Nominal pembayaran melebihi sisa tagihan (Sisa: Rp ' . number_format($remaining, 0, ',', '.') . ')'
                ], 400);
            }

            $newTerbayar = $terbayar + $amount;
            $newRemaining = $total - $newTerbayar;
            
            // Determine status: 'paid' if fully paid, 'partial' if partially paid
            $newStatus = ($newTerbayar >= $total) ? 'paid' : 'partial';
            $isInstallment = $newStatus === 'partial';

            // Update tagihan with proper terbayar column
            DB::table('tagihan')
                ->where('id', $id)
                ->update([
                    'terbayar' => $newTerbayar,
                    'status' => $newStatus,
                    'updated_at' => now(),
                    'tanggal_bayar' => now(),
                    'metode_bayar' => 'Manual (Petugas)'
                ]);

            // Save installment payment history
            if ($amount > 0) {
                InstallmentPayment::create([
                    'tagihan_id' => $id,
                    'user_id' => $tagihan->user_id,
                    'order_id' => 'MANUAL-' . $id . '-' . time(),
                    'amount' => $amount,
                    'payment_method' => 'Manual (Petugas)',
                    'status' => 'success',
                    'transaction_id' => null,
                    'paid_at' => now(),
                ]);
            }

            // Send FCM notification to user
            $user = \App\Models\User::find($tagihan->user_id);
            if ($user && $user->fcm_token) {
                try {
                    $fcmService = new FCMService();
                    
                    // Notification title and body based on payment type
                    $notifTitle = $newStatus === 'paid' 
                        ? 'Pembayaran SPP Lunas' 
                        : 'Cicilan SPP Berhasil';
                    
                    $notifBody = $newStatus === 'paid'
                        ? 'Pembayaran SPP ' . $tagihan->bulan . ' ' . $tagihan->tahun . ' sebesar Rp ' . number_format($tagihan->jumlah, 0, ',', '.') . ' telah lunas.'
                        : 'Cicilan SPP ' . $tagihan->bulan . ' ' . $tagihan->tahun . ' sebesar Rp ' . number_format($amount, 0, ',', '.') . ' berhasil. Sisa: Rp ' . number_format($newRemaining, 0, ',', '.');
                    
                    $fcmService->sendPaymentSuccessNotification($user->fcm_token, [
                        'id' => $tagihan->id,
                        'bulan' => $tagihan->bulan,
                        'tahun' => $tagihan->tahun,
                        'jumlah' => $amount,
                        'metode_bayar' => 'Manual (Petugas)',
                        'tanggal_bayar' => now(),
                        'is_installment' => $isInstallment,
                    ]);
                    logger()->info('✅ FCM notification sent for manual payment');
                } catch (\Exception $e) {
                    logger()->error('❌ FCM notification failed: ' . $e->getMessage());
                }
            }
            
            // Create in-app notification
            $notifTitle = $newStatus === 'paid' 
                ? 'Pembayaran SPP Lunas' 
                : 'Cicilan SPP Berhasil';
            $notifMessage = $newStatus === 'paid'
                ? 'Pembayaran SPP ' . $tagihan->bulan . ' ' . $tagihan->tahun . ' sebesar Rp ' . number_format($tagihan->jumlah, 0, ',', '.') . ' telah lunas.'
                : 'Cicilan SPP ' . $tagihan->bulan . ' ' . $tagihan->tahun . ' sebesar Rp ' . number_format($amount, 0, ',', '.') . ' berhasil diproses oleh petugas. Sisa tagihan: Rp ' . number_format($newRemaining, 0, ',', '.');
            
            DB::table('notifications')->insert([
                'user_id' => $tagihan->user_id,
                'type' => 'Pembayaran',
                'title' => $notifTitle,
                'message' => $notifMessage,
                'data' => json_encode([
                    'payment_id' => $tagihan->id,
                    'bulan' => $tagihan->bulan,
                    'tahun' => $tagihan->tahun,
                    'jumlah' => $amount,
                    'terbayar' => $newTerbayar,
                    'sisa' => $newRemaining,
                    'status' => $newStatus,
                    'is_installment' => $isInstallment,
                ]),
                'is_read' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'status' => true,
                'message' => $newStatus === 'paid' ? 'Pembayaran Lunas!' : 'Cicilan berhasil diproses (Sisa: Rp ' . number_format($newRemaining, 0, ',', '.') . ')',
                'data' => [
                    'id' => $id,
                    'terbayar' => $newTerbayar,
                    'sisa' => $newRemaining,
                    'status' => $newStatus
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal memproses pembayaran: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment history for authenticated user
     */
    public function paymentHistory(Request $request)
    {
        $user = Auth::user();

        try {
            // Get all bills from current year
            $currentYear = now()->year;
            
            $bills = DB::table('tagihan')
                ->where('user_id', $user->id)
                ->where('tahun', '>=', $currentYear - 1) // Get current year and last year
                ->whereIn('status', ['paid', 'lunas', 'unpaid', 'partial', 'pending', 'failed'])
                ->get();

            // Get installment payments for this user
            $installmentPayments = InstallmentPayment::where('user_id', $user->id)
                ->where('status', 'success')
                ->orderBy('paid_at', 'desc')
                ->get();

            // Sort bills: unpaid/partial first (by month order), then paid (by payment date desc)
            $sortedBills = $bills->sortBy([
                // First: Sort by status priority (unpaid/partial first)
                function ($bill) {
                    if (in_array($bill->status, ['unpaid', 'partial', 'pending'])) {
                        return 0; // Unpaid/partial first
                    } else {
                        return 1; // Paid/failed later
                    }
                },
                // Second: For unpaid, sort by year desc then month order
                function ($bill) {
                    if (in_array($bill->status, ['unpaid', 'partial', 'pending'])) {
                        return -$bill->tahun; // Newer year first
                    } else {
                        return 0;
                    }
                },
                // Third: For unpaid, sort by month order (Nov before Dec)
                function ($bill) {
                    if (in_array($bill->status, ['unpaid', 'partial', 'pending'])) {
                        $monthOrder = [
                            'Januari' => 1, 'Februari' => 2, 'Maret' => 3, 'April' => 4,
                            'Mei' => 5, 'Juni' => 6, 'Juli' => 7, 'Agustus' => 8,
                            'September' => 9, 'Oktober' => 10, 'November' => 11, 'Desember' => 12
                        ];
                        return $monthOrder[$bill->bulan] ?? 99;
                    } else {
                        return 0;
                    }
                },
                // Fourth: For paid bills, sort by payment date desc
                function ($bill) {
                    if (in_array($bill->status, ['paid', 'lunas'])) {
                        return $bill->tanggal_bayar ? -strtotime($bill->tanggal_bayar) : 0;
                    } else {
                        return 0;
                    }
                }
            ]);

            // Transform bills data
            $payments = $sortedBills->map(function ($bill) {
                // Determine status for Flutter
                if ($bill->status === 'paid' || $bill->status === 'lunas') {
                    $status = 'success';
                } elseif ($bill->status === 'partial') {
                    $status = 'partial'; // New status for installments
                } elseif ($bill->status === 'unpaid' || $bill->status === 'pending') {
                    $status = 'pending'; // Belum bayar
                } else {
                    $status = 'failed'; // Gagal
                }
                
                // Calculate remaining for partial payments
                $terbayar = (float) ($bill->terbayar ?? 0);
                $remaining = (float) $bill->jumlah - $terbayar;
                
                return [
                    'id' => 'TRX' . str_pad($bill->id, 9, '0', STR_PAD_LEFT),
                    'tagihan_id' => $bill->id,
                    'type' => 'SPP',
                    'month' => $bill->bulan . ' ' . $bill->tahun,
                    'amount' => (int) $bill->jumlah,
                    'terbayar' => (int) $terbayar,
                    'remaining' => (int) $remaining,
                    'date' => $bill->tanggal_bayar ?? $bill->updated_at,
                    'status' => $status,
                    'method' => $bill->metode_bayar ?? ($status === 'pending' ? 'Belum Dibayar' : 'Unknown'),
                    'fine' => (int) ($bill->denda ?? 0),
                    'is_installment' => false,
                ];
            });

            // Transform installment payments and add to the list
            $installmentList = $installmentPayments->map(function ($installment) {
                $tagihan = DB::table('tagihan')->find($installment->tagihan_id);
                $bulan = $tagihan ? $tagihan->bulan . ' ' . $tagihan->tahun : 'Unknown';
                
                return [
                    'id' => 'CIC' . str_pad($installment->id, 9, '0', STR_PAD_LEFT),
                    'tagihan_id' => $installment->tagihan_id,
                    'type' => 'SPP',
                    'month' => $bulan,
                    'amount' => (int) $installment->amount,
                    'terbayar' => (int) $installment->amount,
                    'remaining' => 0,
                    'date' => $installment->paid_at ?? $installment->created_at,
                    'status' => 'success',
                    'method' => ($installment->payment_method ?? 'Midtrans') . ' (Cicilan)',
                    'fine' => 0,
                    'is_installment' => true,
                ];
            });

            // Merge and sort all payments by date desc
            $allPayments = $payments->merge($installmentList)->sortByDesc(function ($p) {
                return strtotime($p['date'] ?? '1970-01-01');
            });

            // Calculate stats
            $successPayments = $payments->where('status', 'success');
            $partialPayments = $payments->where('status', 'partial');
            $pendingPayments = $payments->where('status', 'pending');
            $failedPayments = $payments->where('status', 'failed');
            
            $totalPaid = $successPayments->sum(function ($p) {
                return $p['amount'] + $p['fine'];
            }) + $installmentList->sum('amount');

            return response()->json([
                'status' => true,
                'message' => 'Payment history retrieved successfully',
                'data' => [
                    'payments' => $allPayments->values(),
                    'summary' => [
                        'total_paid' => $totalPaid,
                        'success_count' => $successPayments->count() + $installmentList->count(),
                        'partial_count' => $partialPayments->count(),
                        'pending_count' => $pendingPayments->count(),
                        'failed_count' => $failedPayments->count(),
                        'total_count' => $allPayments->count(),
                        'installment_count' => $installmentList->count(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve payment history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mobile Payment - Allow installment payments from mobile app
     */
    public function mobilePayment(Request $request)
    {
        $user = Auth::user();

        try {
            $request->validate([
                'tagihan_id' => 'required|exists:tagihan,id',
                'amount' => 'required|numeric|min:1000',
            ]);

            $tagihan = DB::table('tagihan')
                ->where('id', $request->tagihan_id)
                ->where('user_id', $user->id) // Ensure user owns this bill
                ->first();

            if (!$tagihan) {
                return response()->json([
                    'status' => false, 
                    'message' => 'Tagihan tidak ditemukan atau bukan milik Anda'
                ], 404);
            }

            $amount = (float) $request->amount;
            $total = (float) $tagihan->jumlah;
            $terbayar = (float) ($tagihan->terbayar ?? 0);
            $remaining = $total - $terbayar;
            
            // Validate amount doesn't exceed remaining
            if ($amount > $remaining) {
                return response()->json([
                    'status' => false, 
                    'message' => 'Nominal pembayaran melebihi sisa tagihan',
                    'data' => [
                        'remaining' => $remaining,
                        'max_amount' => $remaining
                    ]
                ], 400);
            }

            // Validate minimum payment (at least 50k or remaining amount if less)
            $minPayment = min(50000, $remaining);
            if ($amount < $minPayment) {
                return response()->json([
                    'status' => false, 
                    'message' => 'Minimal pembayaran Rp ' . number_format($minPayment, 0, ',', '.'),
                    'data' => [
                        'min_amount' => $minPayment
                    ]
                ], 400);
            }

            $newTerbayar = $terbayar + $amount;
            $newRemaining = $total - $newTerbayar;
            
            // Determine status: 'paid' if fully paid, 'partial' if partially paid
            $newStatus = ($newTerbayar >= $total) ? 'paid' : 'partial';

            // Update tagihan
            DB::table('tagihan')
                ->where('id', $request->tagihan_id)
                ->update([
                    'terbayar' => $newTerbayar,
                    'status' => $newStatus,
                    'updated_at' => now(),
                    'tanggal_bayar' => now(),
                    'metode_bayar' => 'Mobile App (Cicilan)'
                ]);

            return response()->json([
                'status' => true,
                'message' => $newStatus === 'paid' ? 'Pembayaran Lunas! 🎉' : 'Cicilan berhasil diproses ✅',
                'data' => [
                    'tagihan_id' => $request->tagihan_id,
                    'amount_paid' => $amount,
                    'total_terbayar' => $newTerbayar,
                    'remaining' => $newRemaining,
                    'status' => $newStatus,
                    'is_fully_paid' => $newStatus === 'paid',
                    'payment_date' => now()->format('Y-m-d H:i:s'),
                    'method' => 'Mobile App (Cicilan)'
                ]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Data tidak valid',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal memproses pembayaran',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create Installment Payment with Midtrans Gateway
     */
    public function createInstallmentPayment(Request $request)
    {
        $user = Auth::user();

        try {
            $request->validate([
                'tagihan_id' => 'required|exists:tagihan,id',
                'amount' => 'required|numeric|min:1000',
            ]);

            $tagihan = DB::table('tagihan')
                ->where('id', $request->tagihan_id)
                ->where('user_id', $user->id)
                ->first();

            if (!$tagihan) {
                return response()->json([
                    'status' => false, 
                    'message' => 'Tagihan tidak ditemukan atau bukan milik Anda'
                ], 404);
            }

            $amount = (float) $request->amount;
            $total = (float) $tagihan->jumlah;
            $terbayar = (float) ($tagihan->terbayar ?? 0);
            $remaining = $total - $terbayar;
            
            // Validate amount doesn't exceed remaining
            if ($amount > $remaining) {
                return response()->json([
                    'status' => false, 
                    'message' => 'Nominal pembayaran melebihi sisa tagihan',
                    'data' => [
                        'remaining' => $remaining,
                        'max_amount' => $remaining
                    ]
                ], 400);
            }

            // Validate minimum payment (at least 50k or remaining amount if less)
            $minPayment = min(50000, $remaining);
            if ($amount < $minPayment) {
                return response()->json([
                    'status' => false, 
                    'message' => 'Minimal pembayaran Rp ' . number_format($minPayment, 0, ',', '.'),
                    'data' => [
                        'min_amount' => $minPayment
                    ]
                ], 400);
            }

            // Create unique order ID for installment
            $orderId = 'CICILAN-' . $request->tagihan_id . '-' . time();
            
            // Midtrans Server Key (Sandbox)
            $serverKey = 'Mid-server-dFFtiNj8J4--bPOtjAKrQ7Fg';
            $isProduction = false;
            
            // Midtrans Snap URL
            $snapUrl = $isProduction 
                ? 'https://app.midtrans.com/snap/v1/transactions'
                : 'https://app.sandbox.midtrans.com/snap/v1/transactions';
            
            // Prepare Midtrans payload
            $payload = [
                'transaction_details' => [
                    'order_id' => $orderId,
                    'gross_amount' => (int) $amount,
                ],
                'customer_details' => [
                    'first_name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->telepon ?? '',
                ],
                'item_details' => [
                    [
                        'id' => 'cicilan-spp-' . $request->tagihan_id,
                        'price' => (int) $amount,
                        'quantity' => 1,
                        'name' => 'Cicilan SPP ' . $tagihan->bulan . ' ' . $tagihan->tahun,
                        'category' => 'Education',
                    ]
                ],
                'enabled_payments' => [
                    'qris', 'gopay', 'shopeepay', 'other_qris',
                    'bca_va', 'bni_va', 'bri_va', 'mandiri_va', 'permata_va', 'other_va',
                    'indomaret', 'alfamart'
                ],
                'custom_field1' => 'installment', // Mark as installment payment
                'custom_field2' => (string) $request->tagihan_id, // Store tagihan ID
                'custom_field3' => (string) $terbayar, // Store current terbayar amount
            ];

            // Call Midtrans Snap API using HTTP
            $auth = base64_encode($serverKey . ':');
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $snapUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Basic ' . $auth,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $responseData = json_decode($response, true);
            
            if ($httpCode != 200 && $httpCode != 201) {
                \Log::error('Midtrans Snap API Error', [
                    'http_code' => $httpCode,
                    'response' => $responseData,
                    'payload' => $payload
                ]);
                
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal membuat transaksi Midtrans',
                    'error' => $responseData['error_messages'] ?? 'Unknown error'
                ], 500);
            }
            
            $snapToken = $responseData['token'] ?? null;
            $redirectUrl = $responseData['redirect_url'] ?? null;
            
            if (!$snapToken) {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal mendapatkan token pembayaran'
                ], 500);
            }

            // Store payment record as pending
            $paymentRecord = [
                'user_id' => $user->id,
                'tagihan_id' => $request->tagihan_id,
                'order_id' => $orderId,
                'amount' => $amount,
                'status' => 'pending',
                'payment_type' => 'installment',
                'snap_token' => $snapToken,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Insert to payments table (if exists) or store in session/cache
            try {
                DB::table('payments')->insert($paymentRecord);
            } catch (\Exception $e) {
                // If payments table doesn't exist, we'll handle it in notification
                \Log::info('Payment record stored in memory for order: ' . $orderId, $paymentRecord);
            }

            return response()->json([
                'status' => true,
                'message' => 'Token pembayaran cicilan berhasil dibuat',
                'data' => [
                    'snap_token' => $snapToken,
                    'redirect_url' => $redirectUrl,
                    'order_id' => $orderId,
                    'amount' => (int) $amount,
                    'tagihan_id' => $request->tagihan_id,
                    'tagihan_info' => [
                        'month' => $tagihan->bulan . ' ' . $tagihan->tahun,
                        'total_amount' => (int) $total,
                        'terbayar' => (int) $terbayar,
                        'remaining_after_payment' => (int) ($remaining - $amount),
                        'will_be_fully_paid' => $amount >= $remaining
                    ]
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Data tidak valid',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal membuat token pembayaran',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get bill details for mobile payment
     */
    public function getBillDetail(Request $request, $id)
    {
        $user = Auth::user();

        try {
            $tagihan = DB::table('tagihan')
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$tagihan) {
                return response()->json([
                    'status' => false,
                    'message' => 'Tagihan tidak ditemukan'
                ], 404);
            }

            $terbayar = (float) ($tagihan->terbayar ?? 0);
            $total = (float) $tagihan->jumlah;
            $remaining = $total - $terbayar;
            $minPayment = min(50000, $remaining);

            return response()->json([
                'status' => true,
                'message' => 'Detail tagihan berhasil diambil',
                'data' => [
                    'id' => $tagihan->id,
                    'month' => $tagihan->bulan . ' ' . $tagihan->tahun,
                    'total_amount' => (int) $total,
                    'terbayar' => (int) $terbayar,
                    'remaining' => (int) $remaining,
                    'min_payment' => (int) $minPayment,
                    'status' => $tagihan->status,
                    'can_pay_installment' => $remaining > 0,
                    'due_date' => $tagihan->jatuh_tempo,
                    'fine' => (int) ($tagihan->denda ?? 0)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil detail tagihan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Midtrans notification webhook handler
     */
    public function midtransNotification(Request $request)
    {
        try {
            // Get notification data
            $notif = $request->all();

            $orderId = $notif['order_id'] ?? null;
            $transactionStatus = $notif['transaction_status'] ?? null;
            $fraudStatus = $notif['fraud_status'] ?? null;
            $grossAmount = (float) ($notif['gross_amount'] ?? 0);

            if (!$orderId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Order ID not found',
                ], 400);
            }

            // Check if this is an installment payment
            $isInstallment = strpos($orderId, 'CICILAN-') === 0;
            
            if ($isInstallment) {
                // Handle installment payment (format: CICILAN-{tagihan_id}-{timestamp})
                $parts = explode('-', $orderId);
                if (count($parts) < 3) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Invalid installment order ID format',
                    ], 400);
                }
                $tagihanId = $parts[1];
            } else {
                // Handle regular payment (format: SPP-{tagihan_id}-{timestamp})
                $parts = explode('-', $orderId);
                if (count($parts) < 2) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Invalid order ID format',
                    ], 400);
                }
                $tagihanId = $parts[1];
            }

            // Get current tagihan data
            $tagihan = DB::table('tagihan')->where('id', $tagihanId)->first();
            if (!$tagihan) {
                return response()->json([
                    'status' => false,
                    'message' => 'Tagihan not found',
                ], 404);
            }

            // Handle successful payment
            if ($transactionStatus == 'capture' && $fraudStatus == 'accept' || $transactionStatus == 'settlement') {
                
                if ($isInstallment) {
                    // Handle installment payment
                    $currentTerbayar = (float) ($tagihan->terbayar ?? 0);
                    $newTerbayar = $currentTerbayar + $grossAmount;
                    $totalAmount = (float) $tagihan->jumlah;
                    
                    // Determine new status
                    $newStatus = ($newTerbayar >= $totalAmount) ? 'paid' : 'partial';
                    
                    DB::table('tagihan')
                        ->where('id', $tagihanId)
                        ->update([
                            'terbayar' => $newTerbayar,
                            'status' => $newStatus,
                            'tanggal_bayar' => now(),
                            'metode_bayar' => ($notif['payment_type'] ?? 'Midtrans') . ' (Cicilan)',
                            'updated_at' => now(),
                        ]);
                        
                    \Log::info("Installment payment processed: Order {$orderId}, Amount {$grossAmount}, New terbayar: {$newTerbayar}, Status: {$newStatus}");
                } else {
                    // Handle full payment
                    DB::table('tagihan')
                        ->where('id', $tagihanId)
                        ->update([
                            'terbayar' => $grossAmount,
                            'status' => 'paid',
                            'tanggal_bayar' => now(),
                            'metode_bayar' => $notif['payment_type'] ?? 'Midtrans',
                            'updated_at' => now(),
                        ]);
                        
                    \Log::info("Full payment processed: Order {$orderId}, Amount {$grossAmount}");
                }
                
            } elseif ($transactionStatus == 'pending') {
                // Payment is pending, no action needed
                \Log::info("Payment pending: Order {$orderId}");
                
            } elseif ($transactionStatus == 'deny' || $transactionStatus == 'cancel' || $transactionStatus == 'expire') {
                // Payment failed, update status if needed
                \Log::info("Payment failed: Order {$orderId}, Status: {$transactionStatus}");
                
                // Optionally update tagihan status to failed if it was pending
                if ($tagihan->status === 'pending') {
                    DB::table('tagihan')
                        ->where('id', $tagihanId)
                        ->update([
                            'status' => 'failed',
                            'updated_at' => now(),
                        ]);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Notification processed successfully',
            ]);
        } catch (\Exception $e) {
            \Log::error('Midtrans notification error: ' . $e->getMessage(), [
                'request' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'Failed to process notification',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get installment history for a specific tagihan
     */
    public function getInstallmentHistory(Request $request, $tagihanId)
    {
        try {
            $user = Auth::user();
            
            // Verify tagihan belongs to user
            $tagihan = DB::table('tagihan')
                ->where('id', $tagihanId)
                ->where('user_id', $user->id)
                ->first();
                
            if (!$tagihan) {
                return response()->json([
                    'status' => false,
                    'message' => 'Tagihan not found',
                ], 404);
            }
            
            // Get installment payments
            $installments = InstallmentPayment::where('tagihan_id', $tagihanId)
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'order_id' => $item->order_id,
                        'amount' => (int) $item->amount,
                        'payment_method' => $item->payment_method,
                        'status' => $item->status,
                        'paid_at' => $item->paid_at ? $item->paid_at->format('Y-m-d H:i:s') : null,
                        'created_at' => $item->created_at->format('Y-m-d H:i:s'),
                    ];
                });
            
            return response()->json([
                'status' => true,
                'message' => 'Installment history retrieved',
                'data' => [
                    'tagihan' => [
                        'id' => $tagihan->id,
                        'bulan' => $tagihan->bulan,
                        'tahun' => $tagihan->tahun,
                        'jumlah' => (int) $tagihan->jumlah,
                        'terbayar' => (int) $tagihan->terbayar,
                        'remaining' => (int) ($tagihan->jumlah - $tagihan->terbayar),
                        'status' => $tagihan->status,
                    ],
                    'installments' => $installments,
                    'total_paid' => $installments->where('status', 'success')->sum('amount'),
                    'installment_count' => $installments->where('status', 'success')->count(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to get installment history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all installment history for current user
     */
    public function getAllInstallmentHistory(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Get all installment payments for user with tagihan info
            $installments = InstallmentPayment::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($item) {
                    // Get tagihan info
                    $tagihan = DB::table('tagihan')->find($item->tagihan_id);
                    
                    return [
                        'id' => $item->id,
                        'order_id' => $item->order_id,
                        'amount' => (int) $item->amount,
                        'payment_method' => $item->payment_method,
                        'status' => $item->status,
                        'paid_at' => $item->paid_at ? $item->paid_at->format('Y-m-d H:i:s') : null,
                        'created_at' => $item->created_at->format('Y-m-d H:i:s'),
                        'tagihan' => $tagihan ? [
                            'id' => $tagihan->id,
                            'bulan' => $tagihan->bulan,
                            'tahun' => $tagihan->tahun,
                            'jumlah' => (int) $tagihan->jumlah,
                            'terbayar' => (int) $tagihan->terbayar,
                            'status' => $tagihan->status,
                        ] : null,
                    ];
                });
            
            return response()->json([
                'status' => true,
                'message' => 'All installment history retrieved',
                'data' => $installments,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to get installment history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
