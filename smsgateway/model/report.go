package model

// //  example
// var (
// 	ErrGatewayInvalid = errors.New("gateway is empty")
// 	ErrNameInvalid    = errors.New("name is empty")
// 	ErrCodeInvalid    = errors.New("code is empty")
// 	ErrPhoneInvalid   = errors.New("phone is empty")
// )

// SendVerificationCode example
type SendReport struct {
	Gateway int    `json:"gateway,string" example:"gateway" binding:"required"`
	Name    string `json:"name" example:"user name" binding:"required"`
	Code    string `json:"code" example:"123456" binding:"required"`
	Phone   string `json:"phone" example:"886932xxxxxx" binding:"required"`
}

// // Validation example
// func (a *SendVerificationCode) Validation() error {
// 	switch {
// 	case a.Gateway == 0:
// 		return ErrGatewayInvalid
// 	case len(a.Name) == 0:
// 		return ErrNameInvalid
// 	case len(a.Code) == 0:
// 		return ErrCodeInvalid
// 	case len(a.Phone) == 0:
// 		return ErrPhoneInvalid
// 	default:
// 		return nil
// 	}
// }
