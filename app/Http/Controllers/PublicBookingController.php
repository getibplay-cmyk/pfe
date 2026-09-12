<?php

namespace App\Http\Controllers;

use App\Models\PublicBookingRequest;
use App\Models\PublicVehicleListing;
use App\Support\Reservations\PublicBooking;
use Illuminate\Http\Request;

final class PublicBookingController extends Controller
{
    public function index(Request $request, string $company)
    {
        $profile = $request->attributes->get('booking_profile');
        $listings = PublicVehicleListing::where('profile_id', $profile->id)->where('agency_id', $profile->agency_id)->where('published', true)
            ->whereHas('vehicle', fn ($q) => $q->where('operational_status', 'active')->whereHas('category', fn ($category) => $category->where('is_active', true)))
            ->with('vehicle.category')->orderBy('created_at')->paginate(18);

        return view('booking.catalog', compact('profile', 'listings'));
    }

    public function show(Request $request, string $company, string $listing, PublicBooking $bookings)
    {
        $profile = $request->attributes->get('booking_profile');
        $listing = $bookings->listing($profile, $listing);
        $data = $request->validate(['starts_at' => ['nullable', 'required_with:ends_at', 'date'], 'ends_at' => ['nullable', 'required_with:starts_at', 'date', 'after:starts_at']]);
        $quote = ! empty($data['starts_at']) && ! empty($data['ends_at']) ? $bookings->quote($profile, $listing, $data, $request->session()->getId()) : null;

        return view('booking.vehicle', compact('profile', 'listing', 'quote'));
    }

    public function store(Request $request, string $company, string $listing, PublicBooking $bookings)
    {
        $data = $request->validate(['tenant_id' => ['prohibited'], 'agency_id' => ['prohibited'], 'customer_id' => ['prohibited'], 'vehicle_id' => ['prohibited'], 'total_amount' => ['prohibited'], 'website' => ['prohibited'], 'proposal' => ['required', 'string', 'max:10000'], 'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'], 'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:254'], 'phone' => ['nullable', 'required_without:email', 'regex:/^[+0-9 ().-]{6,30}$/'], 'message' => ['nullable', 'string', 'max:1000'], 'consent' => ['accepted']]);
        $profile = $request->attributes->get('booking_profile');
        $booking = $bookings->submit($profile, $listing, $data['proposal'], collect($data)->only(['first_name', 'last_name', 'email', 'phone', 'message'])->all(), $request->session()->getId());

        return to_route('booking.receipt', [$company, $booking->id]);
    }

    public function receipt(Request $request, string $company, string $booking)
    {
        $profile = $request->attributes->get('booking_profile');
        $booking = PublicBookingRequest::where('profile_id', $profile->id)->where('session_hash', hash('sha256', $request->session()->getId()))->findOrFail($booking);

        return view('booking.receipt', compact('profile', 'booking'));
    }
}
