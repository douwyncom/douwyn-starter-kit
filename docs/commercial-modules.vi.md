# Module thương mại

[English source](commercial-modules.md)

Tài liệu tiếng Anh là nguồn chuẩn cho mô hình module thương mại. Tài liệu tiếng Việt này là bản dịch.
Khi hai bản có khác biệt, hãy cập nhật tài liệu tiếng Anh trước rồi đồng bộ bản dịch này.

## Phần lõi nguồn mở và các tiện ích bổ sung trả phí

Douwyn Starter Kit sử dụng mô hình phân phối lõi mở:

- repository này là phần lõi công khai, có thể cài đặt độc lập và được cấp phép theo Apache-2.0;
- `packages/nuxt-api` là một phần của lõi công khai và cũng sử dụng Apache-2.0;
- các module trả phí tùy chọn là những package Composer riêng biệt được phân phối từ repository riêng tư
  hoặc Composer registry riêng tư; và
- mỗi module trả phí chịu sự điều chỉnh của giấy phép thương mại và thỏa thuận hỗ trợ riêng.

Phần lõi công khai phải tiếp tục hữu ích, có thể cài đặt, kiểm thử và phát hành mà không cần module trả
phí hoặc thông tin xác thực registry riêng tư. Việc mua một module chỉ cấp các quyền được nêu trong thỏa
thuận thương mại áp dụng cho module đó; việc này không làm thay đổi giấy phép của phần lõi công khai.

Chỉ mã nguồn module Douwyn được phát triển riêng biệt mới có thể được cung cấp theo điều khoản độc quyền.
Mã nguồn, tài sản hoặc tài liệu được sao chép từ phần lõi Apache-2.0 hoặc dependency bên thứ ba vẫn chịu
các nghĩa vụ về giấy phép và thông báo ban đầu. Hãy duy trì nguồn gốc rõ ràng và thực hiện rà soát pháp
lý trước lần bán đầu tiên.

## Cấu trúc repository

Sử dụng một repository mã nguồn riêng tư cho mỗi module được bán độc lập:

```text
Public
└── douwyn-starter-kit                 Apache-2.0

Private product repositories
├── starter-kit-ledger                 proprietary
├── starter-kit-payment                proprietary
├── starter-kit-telegram               proprietary
└── starter-kit-<module>               proprietary

Private internal repository
└── commercial-integration-fixture     cross-repository release CI
```

Một repository cho mỗi sản phẩm/SKU giúp quản lý độc lập phiên bản, giá, quyền truy cập, bản phát hành,
hỗ trợ và thu hồi. Không cấp cho khách hàng quyền truy cập vào repository chứa các module mà họ chưa mua.

Thư mục `modules/` gốc trong bản checkout này là workspace tích hợp cục bộ đã được ignore. Thư mục có thể
chứa các bản sao tạm thời hoặc các Git checkout riêng trong quá trình phát triển, nhưng repository công
khai không được theo dõi bất kỳ nội dung nào bên dưới thư mục đó. Mã nguồn chuẩn và các release tag của
một module thương mại thuộc về repository riêng tư của chính module đó.

Không tạo một package thương mại dùng chung cho đến khi nhiều module thực sự cần cùng một phần triển
khai được duy trì. Nếu package như vậy được tạo sau này, nó phải có giấy phép, quy trình phát hành, chính
sách tương thích và entitlement khách hàng riêng.

## Ranh giới tương thích Platform

Starter kit cung cấp capability Composer ảo `douwyncom/starter-kit-platform`. Một module thương mại yêu
cầu capability tương thích và đăng ký cùng một ràng buộc runtime thông qua manifest của module.

Ví dụ:

```json
{
    "name": "douwyncom/starter-kit-ledger",
    "license": "proprietary",
    "require": {
        "douwyncom/starter-kit-platform": "^2.0"
    }
}
```

Đây là ranh giới tương thích, không phải quản lý quyền kỹ thuật số. Quyền truy cập thương mại được thực
thi bởi entitlement của repository hoặc registry riêng tư và thỏa thuận mua hàng.

Áp dụng các quy tắc tương thích sau:

- duy trì phiên bản của mỗi module độc lập với bản phát hành starter kit và phiên bản capability Platform;
- yêu cầu phiên bản Platform minor cũ nhất có các contract mà module thực sự sử dụng;
- giữ cho constraint Composer và constraint trong module manifest lúc runtime giống hệt nhau;
- kiểm thử phiên bản Platform thấp nhất được công bố và phiên bản stable mới nhất được hỗ trợ;
- xem thay đổi phá vỡ Platform contract là một Platform major mới;
- xem thay đổi phá vỡ API công khai của module là một module major mới; và
- không bao giờ di chuyển hoặc ghi đè một tag đã phát hành.

Các module phải sử dụng Platform contract công khai thay vì import các lớp ứng dụng bên dưới `App\` hoặc
phụ thuộc trực tiếp vào bảng user của host. Các bản ghi do module sở hữu có thể giữ tham chiếu logic
UUIDv7 chữ thường chuẩn như `user_uuid`, nhưng quyền sở hữu xuyên database phải được phân giải thông qua
ranh giới Platform.

Xem [Platform contract](platform-contract.md) và
[Phát triển module riêng tư](module-development.md).

## Tiêu chuẩn cơ sở của package

Mỗi repository module thương mại nên chứa:

```text
composer.json
LICENSE
LICENSE.vi.md
README.md
README.vi.md
SECURITY.md
SECURITY.vi.md
SUPPORT.md
SUPPORT.vi.md
CHANGELOG.md
config/
database/
docs/
lang/
routes/
src/
tests/
```

Sử dụng `"license": "proprietary"` trong `composer.json`; Composer có tài liệu về định danh này dành cho
phần mềm mã nguồn đóng trong [package schema](https://getcomposer.org/doc/04-schema.md#license).

Các file tiếng Anh `LICENSE`, `README.md`, `SECURITY.md`, `SUPPORT.md` và tài liệu tiếng Anh là nguồn
chuẩn. Các file tiếng Việt là bản dịch và phải giữ nguyên các lệnh kỹ thuật, phiên bản được hỗ trợ, hạn
chế, quy trình bảo mật và ranh giới hỗ trợ.

`LICENSE` của package là thông báo sản phẩm, không thay thế cho Thỏa thuận Thương mại Tổng thể/EULA đã
được rà soát và order form riêng của khách hàng. Pháp nhân ký kết chính xác phải nhất quán trong thông báo
của package, quy trình thanh toán, hóa đơn, order form, kênh hỗ trợ và metadata của repository.

Khi module đóng gói mã nguồn, tài sản, font, fixture hoặc nội dung được tạo từ bên thứ ba, hãy bao gồm các
giấy phép và thông báo bên thứ ba bắt buộc. Không gắn nhãn tài liệu nguồn mở thành tài sản độc quyền.

## Mô hình thương mại được khuyến nghị

Nội dung sau là khuyến nghị về sản phẩm, không phải điều khoản pháp lý ràng buộc:

- một giấy phép áp dụng cho một pháp nhân và số lượng sản phẩm production đã mua;
- development, staging, CI, disaster recovery và backup cho các sản phẩm đó không sử dụng thêm giấy phép
  sản phẩm;
- contractor được ủy quyền chỉ có thể truy cập module để thực hiện công việc cho khách hàng được cấp
  phép và phải chịu nghĩa vụ bảo mật;
- khách hàng có thể sửa đổi mã nguồn được bàn giao để sử dụng nội bộ;
- khách hàng có thể tiếp tục sử dụng các phiên bản đã nhận trong thời gian được cấp phép hợp lệ;
- lần mua đầu tiên bao gồm thời hạn cập nhật và hỗ trợ được xác định, chẳng hạn 12 tháng; và
- việc phân phối công khai, bán lại, cấp phép lại và phân phối lại mã nguồn bị cấm trừ khi một thỏa thuận
  riêng cho phép.

Giữ danh mục ban đầu đơn giản:

| Gói | Phạm vi dự kiến |
| --- | --- |
| Standard | Một pháp nhân và một sản phẩm production |
| Agency | Một số lượng xác định các sản phẩm khách hàng được đăng ký riêng |
| Enterprise | Các điều khoản về pháp nhân, sản phẩm, hỗ trợ và tuân thủ được thương lượng |
| OEM/Redistribution | Quyền triển khai on-premise, bàn giao mã nguồn, bán lại hoặc phân phối lại |

Tránh cấp phép theo server hoặc domain trừ khi sản phẩm thực sự yêu cầu. Autoscaling, môi trường preview,
domain staging và disaster recovery khiến các đơn vị đó khó quản lý.

Hợp đồng phải phân biệt quyền vĩnh viễn để sử dụng các phiên bản đã nhận với subscription mà quyền sử
dụng sẽ chấm dứt. Hợp đồng cũng phải phân biệt việc hết hạn cập nhật với chấm dứt toàn bộ giấy phép. Hãy
để cố vấn pháp lý đủ chuyên môn rà soát các điều khoản cuối cùng, luật áp dụng, bảo hành, trách nhiệm,
quyền riêng tư và thứ tự ưu tiên ngôn ngữ.

## Phân phối và entitlement

Luồng phân phối được khuyến nghị là:

```text
Private GitHub repository
        -> immutable release tag
Private Packagist for Vendors
        -> customer-specific package entitlement
Customer Composer project
```

GitHub là nguồn mã chuẩn và nơi khởi tạo tag. Thông thường, khách hàng nhận archive phát hành thông qua
Private Packagist thay vì lịch sử repository. Private Packagist for Vendors hỗ trợ URL repository và
token riêng cho từng khách hàng, constraint package/phiên bản, minimum stability, giới hạn ngày phát
hành và kiểm soát việc phân phối source URL. Làm theo
[hướng dẫn thiết lập vendor chính thức](https://packagist.com/docs/setup-vendor).

Với giai đoạn thử nghiệm quy mô nhỏ, quyền truy cập VCS Git riêng tư trực tiếp có thể được quản lý thủ
công. Chuyển sang Composer registry có nhận biết khách hàng trước khi việc quản trị quyền truy cập dễ xảy
ra sai sót hoặc khách hàng có thể nhìn thấy các repository mà họ chưa mua.

Các bản ghi entitlement phải nằm trong một hệ thống bán hàng/cấp phép riêng tư, không nằm trong mã nguồn
module:

```text
customers
commercial_products
orders
licenses
license_entitlements
support_contracts
audit_logs
```

Sử dụng primary key UUIDv7 chuẩn theo quy ước `_uuid` của dự án, ví dụ `customer_uuid`, `product_uuid`,
`order_uuid`, `license_uuid` và `entitlement_uuid`.

Một entitlement nên lưu:

- UUID của khách hàng và sản phẩm;
- tên package Composer;
- constraint phiên bản được phép, chẳng hạn `^1.0`;
- số lượng sản phẩm production hoặc gói được phép;
- `updates_until` và `support_until`;
- trạng thái như `active`, `suspended`, `expired` hoặc `revoked`;
- định danh khách hàng/package của registry bên ngoài; và
- phiên bản điều khoản thương mại đã được chấp nhận.

Không lưu token registry của khách hàng dưới dạng plaintext khi registry đã quản lý secret đó. Thay vào
đó, hãy lưu định danh bên ngoài và các sự kiện vòng đời có thể kiểm tra.

## Vòng đời khách hàng

### Mua hàng và onboarding

1. Khách hàng chọn module và gói giấy phép.
2. Khách hàng chấp nhận Master Terms và order form đã được rà soát.
3. Thanh toán được xác nhận.
4. Tạo khách hàng, giấy phép và package entitlement.
5. Chỉ cấp package đã mua, các bản phát hành stable, dải phiên bản đã mua và mốc giới hạn ngày phát hành
   theo hợp đồng.
6. Gửi hướng dẫn xác thực và Composer repository do portal tạo thông qua một kênh đã xác thực.
7. Kiểm thử cài đặt sạch bằng chính entitlement của khách hàng đó.
8. Ghi lại tham chiếu đơn hàng, kênh hỗ trợ và phiên bản điều khoản được chấp nhận.

Các khách hàng đầu tiên có thể được onboarding thủ công. Chỉ tự động hóa payment webhook, thay đổi
entitlement, thông báo gia hạn và lệnh gọi registry API sau khi quy trình thủ công đã ổn định và có thể
kiểm tra.

### Cài đặt

Khách hàng thêm repository được ủy quyền vào ứng dụng riêng tư của họ và lưu thông tin xác thực trong
Composer auth store hoặc secret CI được bảo vệ. Ứng dụng của khách hàng commit `composer.json` riêng và
`composer.lock` đã được rà soát.

Không bao giờ đưa token vào starter kit công khai, `.env.example`, mã nguồn module, ảnh chụp màn hình,
support ticket hoặc Git URL. Định nghĩa repository là cấu hình Composer chỉ dành cho root, vì vậy chúng
thuộc về ứng dụng của khách hàng thay vì một dependency package.

### Cập nhật và gia hạn

Resolve bản cập nhật trong môi trường development/build được kiểm soát, kiểm thử chúng và triển khai lock
file đã được rà soát bằng `composer install`. Production không được chạy `composer update` không giới hạn.

Đối với giấy phép sử dụng vĩnh viễn với thời hạn cập nhật có giới hạn, việc hết hạn nên đặt mốc giới hạn
ngày phát hành/phiên bản trong khi vẫn duy trì quyền truy cập các bản phát hành đã được entitlement trước
đó. Việc gia hạn kéo dài `updates_until` và `support_until`; không yêu cầu package identity mới.

Một major mới có thể yêu cầu nâng cấp trả phí. Chỉ mở rộng constraint phiên bản của entitlement sau khi
việc mua hàng và migration tương thích được phê duyệt.

### Tạm ngừng và chấm dứt

Không thu hồi các bản phát hành đã được entitlement trước đó chỉ vì thời hạn cập nhật kết thúc. Chỉ gỡ bỏ
toàn bộ package entitlement và thu hồi thông tin xác thực khi thỏa thuận cho phép chấm dứt hoàn toàn,
chẳng hạn hoàn tiền, hủy quyền sử dụng theo subscription, vi phạm nghiêm trọng hoặc thông tin xác thực bị
xâm phạm.

Việc thu hồi ngăn các lượt tải xuống được ủy quyền trong tương lai. Nó không thể xóa mã nguồn hoặc archive
đã được bàn giao cho khách hàng. Điều khoản hợp đồng và kiểm soát quyền truy cập, không phải runtime kill
switch, là các cơ chế thực thi chính.

## Cấp phép lúc runtime

Không làm cho bản phát hành module đầu tiên phụ thuộc vào license server có cơ chế call-home. Mã nguồn PHP
được phân phối qua Composer vẫn có thể đọc và sửa đổi, trong khi sự cố dịch vụ cấp phép có thể làm dừng
ứng dụng production của khách hàng.

Nếu xác minh giấy phép lúc runtime được bổ sung sau này, hãy quy định về quyền riêng tư, các định danh
được lưu, timeout, hành vi offline/grace, khôi phục sự cố và điều gì xảy ra khi dịch vụ không khả dụng.
Module tài chính, xác thực hoặc module quan trọng khác không bao giờ được làm hỏng hay xóa dữ liệu khách
hàng vì kiểm tra entitlement thất bại.

## Bảo mật và hỗ trợ

Mỗi module phải đi kèm `SECURITY.md` riêng vì khách hàng registry có thể không có quyền truy cập vào
repository mã nguồn. Chính sách nên xác định:

- các phiên bản được hỗ trợ và mối quan hệ của chúng với thỏa thuận maintenance;
- một kênh báo cáo lỗ hổng riêng tư;
- thông tin chẩn đoán cần cung cấp và dữ liệu tuyệt đối không được gửi;
- quy trình coordinated disclosure và xử lý bản phát hành bảo mật; và
- ranh giới giữa phản hồi bảo mật và hỗ trợ tích hợp thông thường.

Không cam kết SLA xác nhận hoặc khắc phục trừ khi hoạt động hỗ trợ thương mại có thể đáp ứng. Thỏa thuận
áp dụng cho khách hàng kiểm soát phạm vi hỗ trợ trả phí và mục tiêu thời gian phản hồi.

## Cổng kiểm soát phát triển và phát hành

CI cho mỗi bản phát hành module phải:

1. checkout module riêng tư và một fixture starter kit sạch, được hỗ trợ;
2. cài đặt module thông qua Composer path repository;
3. xác minh các constraint Composer và Platform lúc runtime thống nhất;
4. chạy test package, toàn bộ test suite của host, kiểm tra định dạng, dependency audit và secret scan;
5. kiểm thử mọi topology database/cache/queue được quảng bá;
6. kiểm thử cài đặt mới, các bản nâng cấp được hỗ trợ, configuration cache và gỡ package mà không xóa dữ
   liệu khách hàng;
7. xác thực tài liệu Scramble/API mà không ghi artifact riêng tư vào phần lõi công khai;
8. cài đặt candidate tag/archive giống như khách hàng; và
9. chỉ tạo release tag bất biến sau khi CI bắt buộc của branch đạt.

Kiểm thử các database và hạ tầng mà nội dung marketing tuyên bố hỗ trợ. Không quảng cáo “mọi database
server” chỉ dựa trên một lớp trừu tượng.

## Quy tắc repository công khai

`composer.json` và `composer.lock` ở root công khai không được yêu cầu hoặc resolve một package thương
mại. Các tài liệu OpenAPI được tạo, khai báo TypeScript, fixture, snapshot và test công khai cũng phải
được tạo từ phần lõi khi không cài module trả phí.

`composer check:public-boundary` từ chối các file được Git theo dõi bên dưới `modules/` và các yêu cầu
package thương mại trong Composer manifest ở root. Chạy lệnh này trước mỗi lần push hoặc phát hành công
khai. Việc scan artifact được tạo sẽ từ chối namespace module riêng tư và các API path nằm ngoài những
prefix core đã được duyệt rõ ràng; mọi prefix core công khai mới phải được review và bổ sung có chủ đích.

Trước khi phát hành phần lõi công khai:

- xác minh `git ls-files -- 'modules/**'` không trả về file nào;
- tạo lại API/Nuxt artifact công khai mà không có package thương mại;
- xác minh build và test suite công khai không cần credential riêng tư; và
- kiểm thử các module trả phí riêng biệt trong private integration fixture.

## Tài liệu khác

- [Platform contract](platform-contract.md)
- [Phát triển module riêng tư](module-development.md)
- [Quy trình Git và phát hành](git-release.md)
- [Chính sách bảo mật công khai](../SECURITY.md)

Đối với việc mua hàng, cấp phép, quyền truy cập registry hoặc hỗ trợ, hãy gửi email tới
**[contact@douwyn.com](mailto:contact@douwyn.com)** hoặc truy cập
**[https://douwyn.com](https://douwyn.com)**.
